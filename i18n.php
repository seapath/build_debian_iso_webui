<?php
/**
 * Système d'internationalisation (i18n)
 * Supporte le français (fr) et l'anglais (en)
 */

// Répertoire des traductions
define('I18N_DIR', __DIR__ . '/i18n');

// Langues supportées
define('SUPPORTED_LANGUAGES', ['fr', 'en']);

// Langue par défaut
define('DEFAULT_LANGUAGE', 'en');

// Charger la langue
function getLanguage(): string {
    // 1. Vérifier si la langue est définie dans les paramètres GET/POST (priorité absolue)
    if (isset($_GET['lang']) && in_array($_GET['lang'], SUPPORTED_LANGUAGES)) {
        $_SESSION['language'] = $_GET['lang'];
        return $_GET['lang'];
    }
    
    // 2. Vérifier si la langue est définie en session
    if (isset($_SESSION['language']) && in_array($_SESSION['language'], SUPPORTED_LANGUAGES)) {
        return $_SESSION['language'];
    }
    
    // 3. Détecter la langue du navigateur
    $browserLang = detectBrowserLanguage();
    if ($browserLang) {
        $_SESSION['language'] = $browserLang;
        return $browserLang;
    }
    
    // 4. Utiliser la langue par défaut
    $_SESSION['language'] = DEFAULT_LANGUAGE;
    return DEFAULT_LANGUAGE;
}

/**
 * Détecte la langue préférée du navigateur
 */
function detectBrowserLanguage(): ?string {
    if (!isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
        return null;
    }
    
    $acceptLanguage = $_SERVER['HTTP_ACCEPT_LANGUAGE'];
    
    // Parser les langues acceptées (format: fr-FR,fr;q=0.9,en;q=0.8)
    preg_match_all('/([a-z]{1,8}(?:-[a-z]{1,8})?)(?:;q=([0-9.]+))?/i', $acceptLanguage, $matches);
    
    if (empty($matches[1])) {
        return null;
    }
    
    $languages = [];
    for ($i = 0; $i < count($matches[1]); $i++) {
        $lang = strtolower($matches[1][$i]);
        $quality = isset($matches[2][$i]) && $matches[2][$i] !== '' ? (float)$matches[2][$i] : 1.0;
        
        // Extraire le code langue principal (fr-FR -> fr)
        $langCode = explode('-', $lang)[0];
        
        if (in_array($langCode, SUPPORTED_LANGUAGES)) {
            // Garder la qualité la plus élevée si la langue apparaît plusieurs fois
            if (!isset($languages[$langCode]) || $quality > $languages[$langCode]) {
                $languages[$langCode] = $quality;
            }
        }
    }
    
    if (empty($languages)) {
        return null;
    }
    
    // Trier par qualité (plus élevé en premier)
    arsort($languages);
    reset($languages);
    return key($languages);
}

/**
 * Tableau des traductions chargées
 */
$translations = [];

/**
 * Charge les traductions pour une langue donnée
 */
function loadTranslations(string $lang): array {
    $translationFile = I18N_DIR . '/' . $lang . '.php';
    
    if (!file_exists($translationFile)) {
        // Si le fichier n'existe pas, charger la langue par défaut
        $translationFile = I18N_DIR . '/' . DEFAULT_LANGUAGE . '.php';
        if (!file_exists($translationFile)) {
            return [];
        }
    }
    
    return require $translationFile;
}

/**
 * Fonction de traduction principale
 * Usage: t('key') ou t('key', ['param' => 'value'])
 */
function t(string $key, array $params = []): string {
    global $translations;
    
    $lang = getLanguage();
    
    // Charger les traductions si elles ne sont pas encore chargées
    if (empty($translations) || !isset($translations[$lang])) {
        if (!isset($translations[$lang])) {
            $translations[$lang] = [];
        }
        $translations[$lang] = loadTranslations($lang);
    }
    
    // Récupérer la traduction
    $translation = $translations[$lang][$key] ?? $key;
    
    // Remplacer les paramètres si fournis
    if (!empty($params)) {
        foreach ($params as $paramKey => $paramValue) {
            $translation = str_replace('{' . $paramKey . '}', htmlspecialchars($paramValue), $translation);
        }
    }
    
    return $translation;
}

/**
 * Fonction helper pour echo une traduction
 */
function te(string $key, array $params = []): void {
    echo t($key, $params);
}

/**
 * Définir la langue manuellement
 */
function setLanguage(string $lang): void {
    if (in_array($lang, SUPPORTED_LANGUAGES)) {
        $_SESSION['language'] = $lang;
    }
}

// Note: la session est déjà démarrée dans config.php
// Ne pas appeler getLanguage() ici car cela peut causer des problèmes
// La langue sera chargée automatiquement lors de l'appel à t()
?>
