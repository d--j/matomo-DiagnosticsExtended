<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\DiagnosticsExtended\Diagnostic;

use Piwik\Http;
use Piwik\Plugins\Diagnostics\Diagnostic\Diagnostic;
use Piwik\Plugins\Diagnostics\Diagnostic\DiagnosticResult;
use Piwik\Plugins\Diagnostics\Diagnostic\DiagnosticResultItem;
use Piwik\SettingsPiwik;
use Psr\Log\LoggerInterface;

class URLCheck implements Diagnostic
{
    /**
     * @var LoggerInterface
     */
    private $logger;

    const SOCKET_TIMEOUT = 2;
    /**
     * @var string
     */
    private $matomoURL;
    /**
     * @var boolean
     */
    private $criticalIssue;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->matomoURL = SettingsPiwik::getPiwikUrl();
        $this->criticalIssue = false;
    }

    public function execute()
    {
        //TODO: don't check if running in development mode
        $result = new DiagnosticResult("Files that should not be public");
        $result->addItem($this->checkConfigIni());
        $result->addItem($this->checkRequestNotAllowed(
            ".git/info/exclude",
            "Lines that start"
        ));
        $result->addItem($this->checkRequestNotAllowed(
            "tmp/cache/token.php",
            "?php exit"
        ));
        $result->addItem($this->checkRequestNotAllowed(
            "cache/tracker/matomocache_general.php",
            "unserialize"
        ));

        if ($this->criticalIssue) {
            $result->setLongErrorMessage(
                "Please check if your webserver processes the .htaccess files
                generated by Matomo properly. If you are using Nginx, please take a look at the 
                <a href='https://github.com/matomo-org/matomo-nginx/' target='_blank' rel='noopener'>
                official matomo-nginx config</a> for reference.<br>
                Otherwise attackers might be able to read sensitive data."
            );
        }
        return array($result);
    }

    /**
     * @return DiagnosticResultItem
     */
    protected function checkConfigIni()
    {
        $relativeUrl = "config/config.ini.php";
        list($status, $headers, $data) = $this->makeHTTPReququest($relativeUrl);
        if ($this->contains($data, "salt")) {
            return $this->isPublicError($relativeUrl, true);
        }
        if ($this->contains($data, ";")) {
            return new DiagnosticResultItem(
                DiagnosticResult::STATUS_WARNING,
                "<code>$relativeUrl</code> seems to be semi-public. " .
                "While attackers can't read the config now, the file is publicly accessible and if for whatever reason your webserver " .
                "stops executing PHP files, everyone can read your MySQL credentials and more" .
                "Please check your webserver config."
            );
        }
    }

    protected function checkRequestNotAllowed($relativeUrl, $content, $critical = true): DiagnosticResultItem
    {
        list($status, $headers, $data) = $this->makeHTTPReququest($relativeUrl);
//        var_dump($data);
        if (strpos($data, $content) !== false) {
            return $this->isPublicError($relativeUrl, $critical);
        }

        return new DiagnosticResultItem(DiagnosticResult::STATUS_OK, "<code>$relativeUrl</code> doesn't seem to be publically reachable");
    }

    protected function isPublicError($relativeUrl, $critical): DiagnosticResultItem
    {
        if ($critical) {
            $this->criticalIssue = true;
        }
        return new DiagnosticResultItem(
            $critical ? DiagnosticResult::STATUS_ERROR : DiagnosticResult::STATUS_WARNING,
            "<code>$relativeUrl</code> should never be public. Please check your webserver config."
        );
    }

    protected function makeHTTPReququest($relativeUrl)
    {
        $response = Http::sendHttpRequest($this->matomoURL . $relativeUrl, self::SOCKET_TIMEOUT, $userAgent = null,
            $destinationPath = null,
            $followDepth = 0,
            $acceptLanguage = false,
            $byteRange = false,
            $getExtendedInfo = true);
        $status = $response["status"];
        $headers = $response["headers"];
        $data = $response["data"];
        return [$status, $headers, $data];
    }

    protected function contains(string $haystack, string $needle): bool
    {
        return strpos($haystack, $needle) !== false;
    }


}
