<?php
// ============================================================
// sperrliste.php – Sperrliste für den Router
//
// Der Dienst läuft hinter php -S; die Apache-Sperrliste
// (koordination docs/betrieb/apache/webroot.htaccess) erreicht ihn nicht.
// Ohne diese Datei liefert der Router jede Datei unter frontend/ aus,
// auch Punktdateien und Editor-Reste (S63, koordination).
//
// Dieselbe Bedeutung wie die Vorlage. Die Liste steht damit zweimal da;
// tests/sperrliste_test.php misst diese Fassung gegen die beigelegte Kopie
// tests/daten/webroot.htaccess, je Endung, klein und groß.
//
// Gesperrt heißt im Router 404, nicht 403: Er verrät nicht, dass es die
// Datei gibt.
// ============================================================

declare(strict_types=1);

/**
 * Ist der angefragte Pfad gesperrt?
 *
 * $pfad ist der Pfadteil der URI, wie der Router ihn bekommt ("/a/b.css").
 * Gesperrt ist
 *   - jeder Pfad mit einem Teil, der mit einem Punkt beginnt, außer
 *     .well-known (auch "..");
 *   - jeder Basisname, der – ohne Rücksicht auf Groß- und Kleinschreibung –
 *     auf eine Endung der Liste oder auf "~" endet.
 */
function fk_pfad_gesperrt(string $pfad): bool
{
    if (preg_match('#(^|/)\.(?!well-known(/|$))#', $pfad) === 1) {
        return true;
    }
    $name = basename($pfad);
    return preg_match(
        '/(\.(save(\.[0-9]+)?|swp|swo|orig|old|bak|tmp|sql|sqlite|db|log|env|ini|dist|inc|md|sh)|~)$/i',
        $name
    ) === 1;
}
