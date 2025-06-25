<?php
/**
 * Funkcja do pobierania i czyszczenia treści strony
 */

function fetchPageContent($url) {
    try {
        // Sprawdź czy URL jest prawidłowy
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new Exception("Nieprawidłowy URL: $url");
        }
        
        // Konfiguracja cURL
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        $verifySsl = defined('CURL_VERIFY_SSL') ? CURL_VERIFY_SSL : true;
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verifySsl);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verifySsl ? 2 : 0);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
        curl_setopt($ch, CURLOPT_ENCODING, ""); // Automatyczna dekompresja gzip/deflate
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
            'Accept-Language: pl,en-US;q=0.7,en;q=0.3',
            'Accept-Encoding: gzip, deflate',
            'Connection: keep-alive',
            'Upgrade-Insecure-Requests: 1',
        ]);


        $html = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);
        
        if ($curl_error) {
            throw new Exception("Błąd cURL: $curl_error");
        }
        
        if ($http_code !== 200) {
            throw new Exception("HTTP Error $http_code dla URL: $url");
        }
        
        if (empty($html)) {
            throw new Exception("Pusta odpowiedź dla URL: $url");
        }
        
        // Wyczyść treść HTML
        $cleaned_content = cleanHtmlContent($html);

        if (strlen(trim($cleaned_content)) === 0) {
            error_log("No text extracted from URL: $url");
            $body_html = extractBodyHtml($html);
            if ($body_html !== null) {
                return $body_html;
            }
            return "No text extracted from URL";
        }

        return $cleaned_content;
        
    } catch (Exception $e) {
        error_log("Błąd pobierania treści strony $url: " . $e->getMessage());
        return "Nie udało się pobrać treści strony: " . $e->getMessage();
    }
}

function cleanHtmlContent($html) {
    try {
        // Usuń niepotrzebne sekcje
        $html = preg_replace('/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/mi', '', $html);
        $html = preg_replace('/<style\b[^<]*(?:(?!<\/style>)<[^<]*)*<\/style>/mi', '', $html);
        $html = preg_replace('/<noscript\b[^<]*(?:(?!<\/noscript>)<[^<]*)*<\/noscript>/mi', '', $html);
        
        // Usuń komentarze HTML
        $html = preg_replace('/<!--.*?-->/s', '', $html);
        
        // Konwertuj HTML na tekst
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        
        // Usuń niepotrzebne elementy
        $elementsToRemove = ['script', 'style', 'nav', 'header', 'footer', 'aside', 'form'];
        foreach ($elementsToRemove as $tagName) {
            $elements = $dom->getElementsByTagName($tagName);
            for ($i = $elements->length - 1; $i >= 0; $i--) {
                $element = $elements->item($i);
                if ($element->parentNode) {
                    $element->parentNode->removeChild($element);
                }
            }
        }
        
        // Pobierz tekst z głównej treści
        $textContent = '';
        
        // Spróbuj znaleźć główną treść
        $mainSelectors = [
            'main',
            '[role="main"]',
            '.main-content',
            '.content',
            '.post-content',
            '.entry-content',
            '.article-content',
            '#content',
            '#main'
        ];
        
        $mainContent = null;
        foreach ($mainSelectors as $selector) {
            $xpath = new DOMXPath($dom);
            if (strpos($selector, '.') === 0) {
                // Klasa CSS
                $className = substr($selector, 1);
                $nodes = $xpath->query("//*[contains(@class, '$className')]");
            } elseif (strpos($selector, '#') === 0) {
                // ID
                $id = substr($selector, 1);
                $nodes = $xpath->query("//*[@id='$id']");
            } elseif (strpos($selector, '[') === 0) {
                // Atrybut
                $nodes = $xpath->query("//*[@role='main']");
            } else {
                // Tag
                $nodes = $xpath->query("//$selector");
            }
            
            if ($nodes && $nodes->length > 0) {
                $mainContent = $nodes->item(0);
                break;
            }
        }
        
        // Jeśli nie znaleziono głównej treści, użyj body
        if (!$mainContent) {
            $bodyNodes = $dom->getElementsByTagName('body');
            if ($bodyNodes->length > 0) {
                $mainContent = $bodyNodes->item(0);
            }
        }
        
        if ($mainContent) {
            $textContent = extractTextFromNode($mainContent);
        } else {
            $textContent = $dom->textContent;
        }
        
        // Wyczyść tekst
        $textContent = html_entity_decode($textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $textContent = preg_replace('/\s+/', ' ', $textContent);
        $textContent = trim($textContent);
        
        // Ogranicz długość do 3000 znaków
        if (strlen($textContent) > 10000) {
            $textContent = substr($textContent, 0, 10000) . '...';
        }
        
        return $textContent;
        
    } catch (Exception $e) {
        error_log("Błąd czyszczenia HTML: " . $e->getMessage());
        return "Błąd przetwarzania treści strony.";
    }
}

function extractTextFromNode($node) {
    $text = '';
    
    foreach ($node->childNodes as $child) {
        if ($child->nodeType === XML_TEXT_NODE) {
            $text .= $child->textContent . ' ';
        } elseif ($child->nodeType === XML_ELEMENT_NODE) {
            // Dodaj nową linię dla elementów blokowych
            $blockElements = ['p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li', 'br'];
            if (in_array(strtolower($child->nodeName), $blockElements)) {
                $text .= extractTextFromNode($child) . "\n";
            } else {
                $text .= extractTextFromNode($child) . ' ';
            }
        }
    }
    
    return $text;
}

function extractBodyHtml($html) {
    try {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $bodyNodes = $dom->getElementsByTagName('body');
        if ($bodyNodes->length === 0) {
            return null;
        }
        $body = $bodyNodes->item(0);

        $innerHtml = '';
        foreach ($body->childNodes as $child) {
            $innerHtml .= $dom->saveHTML($child);
        }

        if (strlen($innerHtml) > 10000) {
            $innerHtml = substr($innerHtml, 0, 10000) . '...';
        }

        return $innerHtml;
    } catch (Exception $e) {
        error_log("Błąd ekstrakcji HTML body: " . $e->getMessage());
        return null;
    }
}

/**
 * Pobiera treść strony i zapisuje ją w bazie danych
 */
function fetchAndSavePageContent($pdo, $task_item_id, $url) {
    try {
        // Sprawdź czy treść już została pobrana
        $stmt = $pdo->prepare("SELECT page_content FROM task_items WHERE id = ?");
        $stmt->execute([$task_item_id]);
        $existing = $stmt->fetch();
        
        if ($existing && !empty($existing['page_content'])) {
            return $existing['page_content'];
        }
        
        // Pobierz treść strony
        $page_content = fetchPageContent($url);
        
        // Zapisz w bazie danych
        $stmt = $pdo->prepare("UPDATE task_items SET page_content = ? WHERE id = ?");
        $stmt->execute([$page_content, $task_item_id]);
        
        return $page_content;
        
    } catch (Exception $e) {
        error_log("Błąd pobierania i zapisywania treści strony: " . $e->getMessage());
        return "Błąd pobierania treści strony.";
    }
}
?>