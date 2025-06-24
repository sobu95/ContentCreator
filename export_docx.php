<?php
require_once 'auth_check.php'; // Zakładamy, że to zapewnia uwierzytelnienie i dostęp do $_SESSION['user_id']
// Zakładamy również, że istnieje funkcja getDbConnection() zwracająca obiekt PDO.

// --- KONFIGURACJA LOGOWANIA ---
// Ścieżka do pliku logu. Zmieniamy ją na bezpieczniejszą lokalizację, która powinna być zapisywalna.
// Domyślnie: w tym samym katalogu co skrypt PHP.
// JEŚLI TEN PLIK JEST W KATALOGU /public_html/content/
// To log będzie w /public_html/content/export_docx_errors.log
// Jeśli chcesz go w innym miejscu, musisz podać PEŁNĄ, ABSOLUTNĄ ścieżkę.
$logFilePath = __DIR__ . '/export_docx_errors.log';
define('LOG_FILE', $logFilePath);

// Funkcja do logowania błędów
function log_error($message) {
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[{$timestamp}] " . $message . PHP_EOL;

    // Próba zapisu do pliku logu
    if (file_put_contents(LOG_FILE, $log_entry, FILE_APPEND) === false) {
        // Jeśli zapis do pliku logu się nie powiódł, spróbuj zapisać do domyślnego miejsca błędów serwera
        // Lub po prostu wyświetl komunikat na ekranie (jeśli display_errors jest włączone)
        // Używamy error_log, które domyślnie korzysta z konfiguracji serwera, gdy ini_set('error_log', '') jest ustawione
        error_log("NIE MOŻNA ZAPISAĆ DO PLIKU LOGU: " . LOG_FILE . ". Komunikat z php error_log: " . (error_get_last()['message'] ?? 'Brak informacji o błędzie'));
    }
}

// --- TEST UPRAWNIEŃ DO PLIKU LOGU ---
// Sprawdź, czy możemy zapisać w katalogu, gdzie ma być plik logu
$logDir = dirname(LOG_FILE);
$currentLogFile = LOG_FILE; // Zapisz pierwotną ścieżkę

if (!is_writable($logDir)) {
    log_error("OSTRZEŻENIE: Katalog logu {$logDir} nie jest zapisywalny. Próba użycia katalogu tymczasowego.");
    // Jeśli katalog nie jest zapisywalny, spróbujmy alternatywnej ścieżki, np. katalogu tymczasowego systemu
    $altLogPath = sys_get_temp_dir() . '/export_docx_php_errors_' . uniqid() . '.log';
    // Sprawdź, czy katalog tymczasowy jest zapisywalny i czy możemy tam coś zapisać
    if (is_writable(sys_get_temp_dir()) && file_put_contents($altLogPath, "Test zapisu: " . date('Y-m-d H:i:s') . "\n") !== false) {
        define('LOG_FILE', $altLogPath); // Użyj alternatywnej ścieżki
        log_error("INFO: Zapis do alternatywnego pliku logu: " . LOG_FILE);
    } else {
        // Jeśli nie możemy zapisać nigdzie, ustawiamy error_log na pusty string, aby PHP użył domyślnego logu serwera.
        // Wyświetlanie błędów na ekranie (jeśli jest włączone) jest wtedy jedyną opcją debugowania.
        log_error("BŁĄD KRYTYCZNY: Brak możliwości zapisu do pliku logu w {$logDir} ani w katalogu tymczasowym. Ustawiam error_log na domyślny serwera.");
        ini_set('error_log', '');
        // Re-loguj komunikat, który tym razem trafi do domyślnego logu serwera.
        error_log("BŁĄD KRYTYCZNY: Brak możliwości zapisu do pliku logu w {$logDir} ani w katalogu tymczasowym.");
    }
} else {
    // Jeśli katalog jest zapisywalny, upewnij się, że plik logu istnieje lub zostanie utworzony przy pierwszym zapisie.
    // Możemy spróbować stworzyć plik, jeśli nie istnieje, aby sprawdzić uprawnienia do samego pliku
    if (!file_exists(LOG_FILE)) {
        if (file_put_contents(LOG_FILE, "INFO: Plik logu utworzony. Test zapisu: " . date('Y-m-d H:i:s') . "\n") === false) {
            // Jeśli nawet utworzenie pliku się nie powiodło, to jest poważny problem
            log_error("BŁĄD KRYTYCZNY: Nie można utworzyć ani zapisać do pliku logu: " . LOG_FILE . ". Ustawiam error_log na domyślny serwera.");
            ini_set('error_log', '');
            error_log("BŁĄD KRYTYCZNY: Nie można utworzyć ani zapisać do pliku logu: " . LOG_FILE);
        }
    }
}

// Włącz raportowanie wszystkich błędów
error_reporting(E_ALL);
// Skieruj wszystkie błędy PHP do naszego mechanizmu logowania (lub do domyślnego logu serwera, jeśli nasz nie działa)
ini_set('log_errors', 1);
// Ustawienie error_log jest ważne - jeśli LOG_FILE został zmieniony na ścieżkę tymczasową, należy ją tu ustawić.
// Jeśli pozostał w oryginalnym miejscu, a oryginalne miejsce jest zapisywalne, to też działa.
// Jeśli żadna ścieżka nie działa, ini_set('error_log', '') kieruje do domyślnego logu serwera.
if (defined('LOG_FILE')) {
    ini_set('error_log', LOG_FILE);
}


// Wyświetlanie błędów na ekranie jest pomocne podczas debugowania, ale na produkcji lepiej wyłączyć.
// Możesz to tymczasowo wyłączyć, jeśli chcesz przetestować działanie logów bez komunikatów na ekranie.
// ini_set('display_errors', 0);

// --- POCZĄTEK SKRYPTU EXPORT_DOCX ---

// Sprawdzenie parametru task_id
if (!isset($_GET['task_id']) || !is_numeric($_GET['task_id'])) {
    log_error("Błąd: Brak lub niepoprawne task_id w zapytaniu GET.");
    header('Location: tasks.php');
    exit;
}

$task_id = intval($_GET['task_id']);

// Pobranie połączenia z bazą danych
try {
    $pdo = getDbConnection(); // Zakładamy, że ta funkcja jest zdefiniowana i działa poprawnie
} catch (PDOException $e) {
    log_error("Błąd połączenia z bazą danych: " . $e->getMessage());
    http_response_code(500);
    exit('Błąd serwera: Nie można połączyć się z bazą danych.');
}


// Sprawdź czy zadanie należy do użytkownika
try {
    $stmt = $pdo->prepare("
        SELECT t.name as task_name, p.name as project_name
        FROM tasks t
        JOIN projects p ON t.project_id = p.id
        WHERE t.id = ? AND p.user_id = ?
    ");
    $stmt->execute([$task_id, $_SESSION['user_id']]); // Zakładamy, że $_SESSION['user_id'] jest dostępne z auth_check.php
    $task = $stmt->fetch();

    if (!$task) {
        log_error("Błąd autoryzacji: Zadanie o ID {$task_id} nie należy do użytkownika {$_SESSION['user_id']} lub nie istnieje.");
        header('Location: tasks.php');
        exit;
    }
} catch (PDOException $e) {
    log_error("Błąd bazy danych podczas sprawdzania uprawnień dla task_id={$task_id}, user_id={$_SESSION['user_id']}: " . $e->getMessage());
    http_response_code(500);
    exit('Błąd serwera. Nie można zweryfikować danych zadania.');
}


// Pobierz wygenerowane treści
try {
    $stmt = $pdo->prepare("
        SELECT ti.url, gc.verified_text, gc.generated_text
        FROM task_items ti
        JOIN generated_content gc ON ti.id = gc.task_item_id
        WHERE ti.task_id = ? AND ti.status = 'completed'
        ORDER BY ti.id
    ");
    $stmt->execute([$task_id]);
    $contents = $stmt->fetchAll();

    if (empty($contents)) {
        log_error("Brak ukończonych elementów (status='completed') dla zadania ID {$task_id}.");
        header('Location: task_details.php?id=' . $task_id . '&error=no_content');
        exit;
    }
} catch (PDOException $e) {
    log_error("Błąd bazy danych podczas pobierania zawartości dla zadania ID {$task_id}: " . $e->getMessage());
    http_response_code(500);
    exit('Błąd serwera. Nie można pobrać zawartości dla zadania.');
}


// Funkcja do usuwania tylko znaczników <strong> i </strong>
function strip_strong_tags($html) {
    // Użyj wyrażeń regularnych do usunięcia znaczników <strong> i </strong>, zachowując ich zawartość.
    // Dodajemy 's' do flagi regex, aby kropka `.` pasowała również do znaków nowej linii, co jest ważne dla HTML.
    // `U` (ungreedy) sprawia, że dopasowanie jest najkrótsze możliwe.
    $cleaned_html = preg_replace('/<strong>(.*?)<\/strong>/siu', '$1', $html);
    $cleaned_html = preg_replace('/<b>(.*?)<\/b>/siu', '$1', $cleaned_html); // Dodatkowe usunięcie <b>
    return $cleaned_html;
}

// Funkcja do konwersji HTML na Word XML, która teraz używa oczyszczonego tekstu
function htmlToWordXml($html) {
    // Normalizuj HTML: usuń nadmiarowe białe znaki
    $html = preg_replace('/\s+/u', ' ', $html);
    $html = trim($html);

    $xml = '';

    // Użyj DOMDocument do parsowania HTML
    $dom = new DOMDocument();
    // Ustawienie obsługi błędów libxml, aby zbierać błędy zamiast wyświetlać je na ekranie
    libxml_use_internal_errors(true);
    // Ładujemy HTML z deklaracją XML i UTF-8, aby upewnić się, że DOMDocument poprawnie to przetworzy
    // LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD - zapobiega dodawaniu <html> i <body> jeśli ich nie ma
    // LIBXML_NOWARNING | LIBXML_NOERROR - filtruje ostrzeżenia i błędy, które mogą być obsługiwane przez log_error
    $load_result = $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOWARNING | LIBXML_NOERROR);

    // Zbierz i zaloguj ewentualne błędy parsowania HTML
    $errors = libxml_get_errors();
    if (!empty($errors)) {
        foreach ($errors as $error) {
            // Loguj tylko krytyczne błędy, jeśli są
            if ($error->level >= LIBXML_ERR_ERROR) {
                log_error("Błąd DOMDocument podczas parsowania HTML: " . $error->message . " (Linia: {$error->line}, Pozycja: {$error->position}, Kod: {$error->code})");
            }
        }
        libxml_clear_errors(); // Wyczyść zebrane błędy
    }

    // Przetwarzaj węzły DOM, jeśli ładowanie się powiodło
    if ($load_result) {
        // Iterujemy po każdym dziecku głównego dokumentu
        foreach ($dom->childNodes as $node) {
            // Procesujemy tylko te węzły, które nie są pustymi białymi znakami
            if ($node->nodeType === XML_ELEMENT_NODE) {
                 $xml .= processNode($node);
            }
        }
    } else {
        log_error("BŁĄD: Nie udało się załadować fragmentu HTML do DOMDocument.");
    }

    return $xml;
}

// Funkcja do przetwarzania węzłów DOM i generowania Word XML
function processNode($node) {
    $xml = '';

    // Przetwarzanie elementów HTML
    switch ($node->nodeName) {
        case 'h1': // Używamy h1 dla nazwy zadania/projektu, jak było w logach
            $text = trim($node->textContent);
            $xml .= '<w:p><w:pPr><w:pStyle w:val="Heading1"/></w:pPr><w:r><w:t xml:space="preserve">' . htmlspecialchars($text, ENT_XML1, 'UTF-8') . '</w:t></w:r></w:p>';
            break;
        case 'h2':
            $text = trim($node->textContent);
            $xml .= '<w:p><w:pPr><w:pStyle w:val="Heading2"/></w:pPr><w:r><w:t xml:space="preserve">' . htmlspecialchars($text, ENT_XML1, 'UTF-8') . '</w:t></w:r></w:p>';
            break;

        case 'p':
            $xml .= '<w:p><w:pPr><w:pStyle w:val="Normal"/></w:pPr>';
            $xml .= processInlineContent($node); // Przetwarzaj zawartość wewnątrz <p>
            $xml .= '</w:p>';
            break;

        case 'ul': // Obsługa list nieuporządkowanych
            foreach ($node->childNodes as $li_node) {
                if ($li_node->nodeName === 'li') {
                    // Dodaj tabulator przed tekstem listy, aby symulować wcięcie
                    // Najpierw pobierz i przetwórz zawartość li (np. jeśli jest wewnątrz <strong>)
                    $li_content_xml = processInlineContent($li_node);
                    // Jeśli w li jest tylko tekst, dodaj tabulator. Jeśli jest bardziej skomplikowane, pomiń tabulator i użyj normalnego przetwarzania.
                    // Prosta heurystyka: jeśli $li_content_xml zawiera tylko <w:r><w:t>..., dodaj tabulator.
                    if (preg_match('/^<w:r><w:t[^>]*?>.*?<\/w:t><\/w:r>$/siu', $li_content_xml)) {
                         $xml .= '<w:p><w:pPr><w:pStyle w:val="Normal"/></w:pPr><w:r><w:tab/>' . $li_content_xml . '</w:r></w:p>';
                    } else {
                         $xml .= '<w:p><w:pPr><w:pStyle w:val="Normal"/></w:pPr>' . $li_content_xml . '</w:p>';
                    }
                }
            }
            break;

        case 'br': // Obsługa znaczników <br> - wstawiamy nowy wiersz w Wordzie
            $xml .= '<w:r><w:br /></w:r>';
            break;

        case '#text': // Obsługa węzłów tekstowych, które mogą pojawić się bezpośrednio pod elementem nadrzędnym
            $text = trim($node->textContent);
            if (!empty($text)) {
                // Używamy xml:space="preserve", aby zachować białymi znakami wewnątrz tekstu
                $xml .= '<w:p><w:pPr><w:pStyle w:val="Normal"/></w:pPr><w:r><w:t xml:space="preserve">' . htmlspecialchars($text, ENT_XML1, 'UTF-8') . '</w:t></w:r></w:p>';
            }
            break;

        default:
            // Dla nieobsługiwanych elementów, spróbuj przetworzyć ich zawartość rekurencyjnie
            // To pozwoli na zachowanie tekstu nawet jeśli znacznik nie jest bezpośrednio obsługiwany
            foreach ($node->childNodes as $child) {
                $xml .= processNode($child);
            }
            break;
    }

    return $xml;
}

// Funkcja do przetwarzania elementów inline (np. strong, b, a wewnątrz <p>)
function processInlineContent($node) {
    $xml = '';

    foreach ($node->childNodes as $child) {
        switch ($child->nodeName) {
            case '#text':
                $text = trim($child->textContent);
                if (!empty($text)) {
                    // Używamy xml:space="preserve", aby zachować białymi znakami wewnątrz tekstu
                    $xml .= '<w:r><w:t xml:space="preserve">' . htmlspecialchars($text, ENT_XML1, 'UTF-8') . '</w:t></w:r>';
                }
                break;

            case 'strong':
            case 'b':
                // Bezpośrednio pobieramy zawartość wewnętrzną i dodajemy formatowanie pogrubienia
                $text_content = '';
                foreach ($child->childNodes as $inner_child) {
                    $text_content .= processInlineContent($inner_child); // Rekurencyjnie przetwarzaj zawartość wewnątrz strong/b
                }
                if (!empty(trim($text_content))) {
                    $xml .= '<w:r><w:rPr><w:b/></w:rPr>' . $text_content . '</w:r>';
                }
                break;

            case 'a':
                $text = trim($child->textContent);
                $href = $child->getAttribute('href');
                // Konwertuj link jako tekst z formatowaniem (niebieski, podkreślony)
                $xml .= '<w:r><w:rPr><w:color w:val="0000FF"/><w:u w:val="single"/></w:rPr><w:t xml:space="preserve">' . htmlspecialchars($text, ENT_XML1, 'UTF-8') . '</w:t></w:r>';
                // Dodaj URL w nawiasie po tekście linku, jeśli URL jest inny niż tekst linku
                if ($href && $href !== $text) {
                    $xml .= '<w:r><w:t xml:space="preserve"> (' . htmlspecialchars($href, ENT_XML1, 'UTF-8') . ')</w:t></w:r>';
                }
                break;

            case 'br': // Obsługa <br> wewnątrz inline
                $xml .= '<w:r><w:br /></w:r>';
                break;

            default:
                // Dla innych nieobsługiwanych elementów inline, przetwarzamy ich zawartość rekurencyjnie
                $xml .= processInlineContent($child);
                break;
        }
    }

    return $xml;
}

// --- Utwórz dokument DOCX ---
$filename = 'content_' . $task_id . '_' . date('Y-m-d_H-i-s') . '.docx';

// Rozpocznij buforowanie wyjścia
ob_start();

// Nagłówki dla pliku DOCX
header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

// --- Logowanie tymczasowych ścieżek ---
$temp_dir_base = sys_get_temp_dir();
// Używamy uniqid(), aby zapewnić unikalność katalogu i uniknąć konfliktów
$temp_dir = rtrim($temp_dir_base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'docx_' . uniqid();
log_error("Rozpoczęcie generowania DOCX dla zadania ID {$task_id}. Tymczasowy katalog bazowy: {$temp_dir_base}. Docelowy katalog tymczasowy: {$temp_dir}");

// Utwórz tymczasowy katalog
if (!mkdir($temp_dir)) {
    log_error("BŁĄD KRYTYCZNY: Nie udało się utworzyć katalogu tymczasowego: {$temp_dir}. Sprawdź uprawnienia dla {$temp_dir_base}.");
    http_response_code(500);
    exit('Błąd serwera: Nie można utworzyć katalogu tymczasowego.');
}

// Utwórz strukturę katalogów DOCX
try {
    // Tworzenie podkatalogów z użyciem stałej DIRECTORY_SEPARATOR dla większej przenośności
    if (!mkdir($temp_dir . DIRECTORY_SEPARATOR . '_rels') ||
        !mkdir($temp_dir . DIRECTORY_SEPARATOR . 'word') ||
        !mkdir($temp_dir . DIRECTORY_SEPARATOR . 'word' . DIRECTORY_SEPARATOR . '_rels')) {
        throw new Exception("Nie udało się utworzyć wymaganych podkatalogów w {$temp_dir}");
    }
} catch (Exception $e) {
    log_error("BŁĄD KRYTYCZNY: Problem z tworzeniem struktury katalogów DOCX w {$temp_dir}: " . $e->getMessage());
    http_response_code(500);
    deleteDirectory($temp_dir); // Usuń utworzony katalog, jeśli coś poszło nie tak
    exit('Błąd serwera: Problem z tworzeniem struktury katalogów DOCX.');
}

// Plik [Content_Types].xml
$content_types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
    <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
    <Default Extension="xml" ContentType="application/xml"/>
    <Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
    <Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>
</Types>';
if (file_put_contents($temp_dir . DIRECTORY_SEPARATOR . '[Content_Types].xml', $content_types) === false) {
    log_error("BŁĄD: Nie udało się zapisać pliku: {$temp_dir}/[Content_Types].xml");
}

// Plik _rels/.rels
$rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>
</Relationships>';
if (file_put_contents($temp_dir . DIRECTORY_SEPARATOR . '_rels' . DIRECTORY_SEPARATOR . '.rels', $rels) === false) {
    log_error("BŁĄD: Nie udało się zapisać pliku: {$temp_dir}/_rels/.rels");
}

// Plik word/_rels/document.xml.rels
$doc_rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>';
if (file_put_contents($temp_dir . DIRECTORY_SEPARATOR . 'word' . DIRECTORY_SEPARATOR . '_rels' . DIRECTORY_SEPARATOR . 'document.xml.rels', $doc_rels) === false) {
    log_error("BŁĄD: Nie udało się zapisać pliku: {$temp_dir}/word/_rels/document.xml.rels");
}

// Plik styles.xml z definicjami stylów
$styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
    <w:docDefaults>
        <w:rPrDefault>
            <w:rPr>
                <w:rFonts w:ascii="Calibri" w:eastAsia="Calibri" w:hAnsi="Calibri" w:cs="Calibri"/>
                <w:sz w:val="22"/>
                <w:szCs w:val="22"/>
                <w:lang w:val="pl-PL" w:eastAsia="en-US" w:bidi="ar-SA"/>
            </w:rPr>
        </w:rPrDefault>
        <w:pPrDefault>
            <w:pPr>
                <w:spacing w:after="200" w:line="276" w:lineRule="auto"/>
            </w:pPr>
        </w:pPrDefault>
    </w:docDefaults>
    <w:style w:type="paragraph" w:styleId="Normal">
        <w:name w:val="Normal"/>
        <w:qFormat/>
        <w:pPr>
            <w:spacing w:after="200" w:line="276" w:lineRule="auto"/>
        </w:pPr>
    </w:style>
    <w:style w:type="paragraph" w:styleId="Heading1">
        <w:name w:val="heading 1"/>
        <w:basedOn w:val="Normal"/>
        <w:next w:val="Normal"/>
        <w:link w:val="Heading1Char"/>
        <w:uiPriority w:val="9"/>
        <w:qFormat/>
        <w:pPr>
            <w:keepNext/>
            <w:keepLines/>
            <w:spacing w:before="480" w:after="0"/>
            <w:outlineLvl w:val="0"/>
        </w:pPr>
        <w:rPr>
            <w:rFonts w:asciiTheme="majorHAnsi" w:eastAsiaTheme="majorEastAsia" w:hAnsiTheme="majorHAnsi" w:cstheme="majorBidi"/>
            <w:b/>
            <w:bCs/>
            <w:color w:val="2F5496" w:themeColor="accent1" w:themeShade="BF"/>
            <w:sz w:val="32"/>
            <w:szCs w:val="32"/>
        </w:rPr>
    </w:style>
    <w:style w:type="paragraph" w:styleId="Heading2">
        <w:name w:val="heading 2"/>
        <w:basedOn w:val="Normal"/>
        <w:next w:val="Normal"/>
        <w:link w:val="Heading2Char"/>
        <w:uiPriority w:val="9"/>
        <w:unhideWhenUsed/>
        <w:qFormat/>
        <w:pPr>
            <w:keepNext/>
            <w:keepLines/>
            <w:spacing w:before="200" w:after="0"/>
            <w:outlineLvl w:val="1"/>
        </w:pPr>
        <w:rPr>
            <w:rFonts w:asciiTheme="majorHAnsi" w:eastAsiaTheme="majorEastAsia" w:hAnsiTheme="majorHAnsi" w:cstheme="majorBidi"/>
            <w:b/>
            <w:bCs/>
            <w:color w:val="2F5496" w:themeColor="accent1" w:themeShade="BF"/>
            <w:sz w:val="26"/>
            <w:szCs w:val="26"/>
        </w:rPr>
    </w:style>
    <w:style w:type="character" w:styleId="Heading1Char">
        <w:name w:val="Heading 1 Char"/>
        <w:basedOn w:val="DefaultParagraphFont"/>
        <w:link w:val="Heading1"/>
        <w:uiPriority w:val="9"/>
        <w:rPr>
            <w:rFonts w:asciiTheme="majorHAnsi" w:eastAsiaTheme="majorEastAsia" w:hAnsiTheme="majorHAnsi" w:cstheme="majorBidi"/>
            <w:b/>
            <w:bCs/>
            <w:color w:val="2F5496" w:themeColor="accent1" w:themeShade="BF"/>
            <w:sz w:val="32"/>
            <w:szCs w:val="32"/>
        </w:rPr>
    </w:style>
    <w:style w:type="character" w:styleId="Heading2Char">
        <w:name w:val="Heading 2 Char"/>
        <w:basedOn w:val="DefaultParagraphFont"/>
        <w:link w:val="Heading2"/>
        <w:uiPriority w:val="9"/>
        <w:rPr>
            <w:rFonts w:asciiTheme="majorHAnsi" w:eastAsiaTheme="majorEastAsia" w:hAnsiTheme="majorHAnsi" w:cstheme="majorBidi"/>
            <w:b/>
            <w:bCs/>
            <w:color w:val="2F5496" w:themeColor="accent1" w:themeShade="BF"/>
            <w:sz w:val="26"/>
            <w:szCs w:val="26"/>
        </w:rPr>
    </w:style>
    <w:style w:type="character" w:styleId="DefaultParagraphFont">
        <w:name w:val="Default Paragraph Font"/>
        <w:uiPriority w:val="1"/>
        <w:semiHidden/>
        <w:unhideWhenUsed/>
    </w:style>
</w:styles>';
if (file_put_contents($temp_dir . DIRECTORY_SEPARATOR . 'word' . DIRECTORY_SEPARATOR . 'styles.xml', $styles) === false) {
    log_error("BŁĄD: Nie udało się zapisać pliku: {$temp_dir}/word/styles.xml");
}

// --- Budowanie głównego XML-a dokumentu z treści ---
$document_content_parts = [];
// Dodajemy XML Declaration na początku
$document_content_parts[] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
$document_content_parts[] = '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
$document_content_parts[] = '    <w:body>';

$content_index = 0;
foreach ($contents as $content) {
    $content_index++;
    // Pobierz tekst, najpierw verified_text, jeśli nie ma, to generated_text
    $text_source = $content['verified_text'] ?? $content['generated_text'] ?? '';

    // LOGOWANIE PODGLĄDU TEKSTU (PRZED CZYSZCZENIEM)
    $text_preview_raw = mb_substr($text_source, 0, 200) . (mb_strlen($text_source) > 200 ? '...' : '');
    log_error("INFO: Przetwarzanie elementu {$content_index}/" . count($contents) . " dla zadania {$task_id}. URL: {$content['url']}. Podgląd surowego tekstu: '{$text_preview_raw}'");

    // CZYSZCZENIE TEKSTU ZE ZNACZNIKÓW <strong> i </strong>
    $cleaned_text = strip_strong_tags($text_source);

    // LOGOWANIE PODGLĄDU TEKSTU (PO CZYSZCZENIU)
    $text_preview_cleaned = mb_substr($cleaned_text, 0, 200) . (mb_strlen($cleaned_text) > 200 ? '...' : '');
    log_error("INFO: Przetwarzanie elementu {$content_index}/" . count($contents) . " dla zadania {$task_id}. URL: {$content['url']}. Podgląd oczyszczonego tekstu: '{$text_preview_cleaned}'");

    // Przygotowanie URL jako nagłówka H1
    $url_escaped = htmlspecialchars($content['url'] ?? 'Brak URL', ENT_XML1, 'UTF-8');
    $document_content_parts[] = '<w:p><w:pPr><w:pStyle w:val="Heading1"/></w:pPr><w:r><w:t xml:space="preserve">' . $url_escaped . '</w:t></w:r></w:p>';

    // Konwertuj OCZYSZCZONY HTML na Word XML
    $document_content_parts[] = htmlToWordXml($cleaned_text);

    // Dodaj pusty akapit jako separację między elementami, jeśli to nie jest ostatni element
    if ($content_index < count($contents)) {
        $document_content_parts[] = '<w:p><w:pPr><w:pStyle w:val="Normal"/></w:pPr><w:r><w:t xml:space="preserve"> </w:t></w:r></w:p>';
    }
}

$document_content_parts[] = '</w:body></w:document>';
$document_content = implode('', $document_content_parts); // Połącz wszystkie części w jeden string

$document_xml_path = $temp_dir . DIRECTORY_SEPARATOR . 'word' . DIRECTORY_SEPARATOR . 'document.xml';
if (file_put_contents($document_xml_path, $document_content) === false) {
    log_error("BŁĄD KRYTYCZNY: Nie udało się zapisać pliku XML dokumentu: {$document_xml_path}. Treść:\n" . substr($document_content, 0, 500) . '...'); // Loguj początek treści XML
    http_response_code(500);
    deleteDirectory($temp_dir);
    exit('Błąd serwera: Nie można zapisać pliku document.xml.');
}

// --- Utwórz archiwum DOCX ---
$zip = new ZipArchive();
$docx_filename = $temp_dir . '.docx'; // Nazwa tymczasowego pliku .docx

// Uruchom ZipArchive z obsługą błędów
if ($zip->open($docx_filename, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    log_error("BŁĄD KRYTYCZNY: Nie udało się utworzyć archiwum ZIP: {$docx_filename}. Kod błędu ZipArchive: " . $zip->status);
    http_response_code(500);
    deleteDirectory($temp_dir);
    exit('Błąd serwera: Nie udało się utworzyć pliku DOCX.');
}

// Dodaj pliki do archiwum
try {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($temp_dir),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    $added_files_count = 0;
    foreach ($files as $name => $file) {
        if (!$file->isDir()) {
            $filePath = $file->getRealPath();
            $relativePath = substr($filePath, strlen($temp_dir) + 1);
            // Dodaj plik do archiwum. Jeśli się nie powiedzie, zaloguj ostrzeżenie.
            if ($zip->addFile($filePath, $relativePath)) {
                $added_files_count++;
            } else {
                log_error("OSTRZEŻENIE: Nie udało się dodać pliku do archiwum ZIP: {$relativePath} z {$filePath}.");
            }
        }
    }
    log_error("INFO: Pomyślnie dodano {$added_files_count} plików do archiwum ZIP dla zadania {$task_id}.");

} catch (Exception $e) {
    log_error("BŁĄD KRYTYCZNY: Wystąpił wyjątek podczas dodawania plików do ZIP: " . $e->getMessage());
    http_response_code(500);
    $zip->close(); // Upewnij się, że archiwum jest zamknięte, nawet jeśli wystąpił błąd
    deleteDirectory($temp_dir);
    if (file_exists($docx_filename)) { // Usuń niekompletny plik ZIP, jeśli istnieje
        unlink($docx_filename);
    }
    exit('Błąd serwera: Problem podczas dodawania plików do archiwum.');
}

// Zamknij archiwum ZIP
if (!$zip->close()) {
    log_error("BŁĄD KRYTYCZNY: Nie udało się zamknąć archiwum ZIP: {$docx_filename}. Kod błędu ZipArchive: " . $zip->status);
    http_response_code(500);
    deleteDirectory($temp_dir);
    if (file_exists($docx_filename)) {
        unlink($docx_filename);
    }
    exit('Błąd serwera: Nie udało się zamknąć pliku DOCX.');
}

log_error("INFO: Plik DOCX {$docx_filename} został pomyślnie utworzony.");

// --- Wyślij plik do pobrania ---
if (file_exists($docx_filename)) {
    // Upewnij się, że żaden dodatkowy output nie jest wysyłany do klienta przed odczytem pliku
    ob_end_clean(); // Wyczyść bufor wyjścia przed wysłaniem pliku

    // Ponownie ustaw nagłówki, ponieważ ob_end_clean() może je usunąć w zależności od konfiguracji
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    // Odczytaj i wyślij zawartość pliku
    readfile($docx_filename);
} else {
    log_error("BŁĄD KRYTYCZNY: Plik DOCX nie istnieje po jego utworzeniu: {$docx_filename}");
    http_response_code(500);
    deleteDirectory($temp_dir);
    exit('Błąd serwera: Plik DOCX nie został znaleziony do wysłania.');
}

// --- CZYSZCZENIE ---
// Funkcja do bezpiecznego usuwania katalogów i ich zawartości
function deleteDirectory($dir) {
    if (!is_dir($dir)) {
        return false; // Nie jest to katalog lub nie istnieje
    }
    $items = scandir($dir); // Pobierz listę plików i katalogów wewnątrz $dir
    if ($items === false) {
        log_error("BŁĄD: Nie można odczytać zawartości katalogu {$dir} w funkcji deleteDirectory.");
        return false; // Nie udało się odczytać katalogu
    }

    foreach ($items as $item) {
        // Pomijamy wpisy bieżącego i nadrzędnego katalogu
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item; // Tworzymy pełną ścieżkę do elementu

        // Jeśli to katalog, usuwamy go rekurencyjnie
        if (is_dir($path)) {
            if (!deleteDirectory($path)) {
                log_error("BŁĄD: Nie udało się usunąć podkatalogu: {$path} w deleteDirectory.");
                return false; // Jeśli rekurencyjne usuwanie się nie powiedzie, zwracamy false
            }
        } else { // Jeśli to plik, usuwamy go
            if (!unlink($path)) {
                log_error("BŁĄD: Nie udało się usunąć pliku: {$path} w deleteDirectory.");
                return false; // Jeśli usunięcie pliku się nie powiedzie, zwracamy false
            }
        }
    }

    // Po usunięciu wszystkich elementów, usuwamy sam katalog
    if (!rmdir($dir)) {
        log_error("BŁĄD: Nie udało się usunąć katalogu: {$dir} w deleteDirectory.");
        return false;
    }
    return true; // Wszystko pomyślnie usunięte
}

// Usuń tymczasowe pliki i katalogi po wysłaniu pliku DOCX
// Najpierw usuwamy folder tymczasowy z zawartością (w tym utworzony plik .docx)
if (!deleteDirectory($temp_dir)) {
    log_error("OSTRZEŻENIE: Nie udało się całkowicie usunąć tymczasowych plików DOCX w {$temp_dir}");
}

// Zakończ buforowanie i skrypt
// ob_end_flush(); // Zostało wywołane wcześniej w przypadku odczytu pliku
exit; // Zawsze dobrze zakończyć skrypt jawnie
?>