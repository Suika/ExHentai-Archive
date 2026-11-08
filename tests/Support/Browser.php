<?php

namespace Tests\Support;

use HeadlessChromium\Browser as ChromeBrowser;
use HeadlessChromium\BrowserFactory;
use HeadlessChromium\Communication\Message;
use HeadlessChromium\Page;
use PHPUnit\Framework\Assert;

/**
 * Headless Chromium for the UI smoke tests (tests/Browser), talking to the app on port 6767.
 *
 *   $b = Browser::desktop();            // 1280x800, mouse
 *   $b = Browser::phone();              // 390x844, DPR 3, touch, (hover: none)
 *   $b->open('/?action=gallery&id=1', ['viewType' => 'mpv']);
 *   $b->waitFor('$(".page-count").text() === "1/5"');
 */
final class Browser
{
    private static ?ChromeBrowser $chrome = null;

    private Page $page;
    private bool $phone;

    private function __construct(bool $phone)
    {
        $this->phone = $phone;
        $this->page = self::chrome()->createPage();

        if ($phone) {
            $this->send('Emulation.setDeviceMetricsOverride', array(
                'width' => 390, 'height' => 844, 'deviceScaleFactor' => 3, 'mobile' => true,
                'screenWidth' => 390, 'screenHeight' => 844,
            ));
            $this->send('Emulation.setTouchEmulationEnabled', array('enabled' => true, 'maxTouchPoints' => 5));
            $this->send('Emulation.setEmulatedMedia', array('features' => array(
                array('name' => 'hover', 'value' => 'none'),
                array('name' => 'pointer', 'value' => 'coarse'),
            )));
        } else {
            $this->send('Emulation.setDeviceMetricsOverride', array(
                'width' => 1280, 'height' => 800, 'deviceScaleFactor' => 1, 'mobile' => false,
                'screenWidth' => 1280, 'screenHeight' => 800,
            ));
        }

        // collect uncaught JS errors of every page load
        $this->page->addPreScript(
            'window.__errors = []; window.addEventListener("error", function(e) { window.__errors.push(String(e.message)); });'
        );
    }

    public static function desktop(): self
    {
        return new self(false);
    }

    public static function phone(): self
    {
        return new self(true);
    }

    private static function chrome(): ChromeBrowser
    {
        if (!self::$chrome) {
            $factory = new BrowserFactory('chromium');
            self::$chrome = $factory->createBrowser(array(
                'noSandbox' => true,
                'headless' => true,
                'customFlags' => array('--disable-dev-shm-usage', '--disable-gpu'),
                'sendSyncDefaultTimeout' => 10000,
            ));
        }

        return self::$chrome;
    }

    public function close(): void
    {
        $this->page->close();
    }

    /**
     * Opens a path of the app. $storage is written to localStorage before the page's scripts run.
     */
    public function open(string $path, array $storage = array()): self
    {
        if ($storage) {
            $script = '';
            foreach ($storage as $key => $value) {
                $script .= sprintf('localStorage.setItem(%s, %s);', json_encode($key), json_encode($value));
            }
            $this->page->addPreScript($script);
        }

        $this->page->navigate(Api::BASE.$path)->waitForNavigation(Page::LOAD, 15000);

        return $this;
    }

    /** Evaluates a JS expression and returns its (JSON-compatible) value */
    public function js(string $expression)
    {
        return $this->page->evaluate($expression)->getReturnValue(10000);
    }

    /** Waits until the JS expression is truthy */
    public function waitFor(string $expression, int $timeoutMs = 8000, string $message = ''): void
    {
        $end = microtime(true) + $timeoutMs / 1000;
        do {
            if ($this->js('!!('.$expression.')')) {
                return;
            }
            usleep(50000);
        } while (microtime(true) < $end);

        Assert::fail(($message ?: 'Timed out waiting for: '.$expression)."\nJS errors: ".json_encode($this->errors()));
    }

    /** @return string[] uncaught JS errors since the last page load */
    public function errors(): array
    {
        return $this->js('window.__errors || []');
    }

    /**
     * Presses and releases a key, e.g. "ArrowRight", "Escape", "Enter" (sets both e.key and the legacy e.keyCode).
     */
    public function key(string $key): void
    {
        $codes = array('ArrowLeft' => 37, 'ArrowUp' => 38, 'ArrowRight' => 39, 'ArrowDown' => 40,
            'Enter' => 13, 'Escape' => 27);
        $params = array('key' => $key, 'code' => $key, 'windowsVirtualKeyCode' => $codes[$key] ?? 0);

        $this->send('Input.dispatchKeyEvent', array('type' => 'rawKeyDown') + $params);
        $this->send('Input.dispatchKeyEvent', array('type' => 'keyUp') + $params);
    }

    /** Types text into the focused element */
    public function type(string $text): void
    {
        foreach (mb_str_split($text) as $char) {
            $this->send('Input.dispatchKeyEvent', array('type' => 'keyDown', 'text' => $char));
            $this->send('Input.dispatchKeyEvent', array('type' => 'keyUp'));
        }
    }

    /** A tap (phone) or click (desktop) at viewport coordinates */
    public function tap(int $x, int $y): void
    {
        if ($this->phone) {
            $this->send('Input.synthesizeTapGesture', array('x' => $x, 'y' => $y, 'gestureSourceType' => 'touch'));
        } else {
            foreach (array('mousePressed', 'mouseReleased') as $type) {
                $this->send('Input.dispatchMouseEvent',
                    array('type' => $type, 'x' => $x, 'y' => $y, 'button' => 'left', 'clickCount' => 1));
            }
        }
    }

    /** A one finger swipe from one point to another (touch events) */
    public function swipe(int $fromX, int $fromY, int $toX, int $toY): void
    {
        $this->send('Input.dispatchTouchEvent', array('type' => 'touchStart', 'touchPoints' => array(array('x' => $fromX, 'y' => $fromY))));
        for ($i = 1; $i <= 5; $i++) {
            $x = (int)($fromX + ($toX - $fromX) * $i / 5);
            $y = (int)($fromY + ($toY - $fromY) * $i / 5);
            $this->send('Input.dispatchTouchEvent', array('type' => 'touchMove', 'touchPoints' => array(array('x' => $x, 'y' => $y))));
        }
        $this->send('Input.dispatchTouchEvent', array('type' => 'touchEnd', 'touchPoints' => array()));
    }

    /** @return string[] URLs of api.php?action=archiveimage requests made by the page */
    public function pageImageRequests(): array
    {
        return $this->js('performance.getEntriesByType("resource").map(function(e) { return e.name; })'
            .'.filter(function(u) { return u.indexOf("action=archiveimage") >= 0; })');
    }

    /** Saves a PNG screenshot (for looking at the layout by hand, not used in assertions) */
    public function screenshot(string $file): void
    {
        $this->page->screenshot()->saveToFile($file);
    }

    private function send(string $method, array $params): void
    {
        $response = $this->page->getSession()->sendMessageSync(new Message($method, $params), 10000);
        if (!$response->isSuccessful()) {
            Assert::fail("CDP $method failed: ".$response->getErrorMessage());
        }
    }
}
