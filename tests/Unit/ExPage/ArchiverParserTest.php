<?php

namespace Tests\Unit\ExPage;

use PHPUnit\Framework\TestCase;
use Tests\Support\Fixtures;

/**
 * archiver.php parsing (ExPage_Archiver) and the "dlcheck" detection of ExClient::buttonPress.
 */
final class ArchiverParserTest extends TestCase
{
    public function testFormPage(): void
    {
        $html = Fixtures::html('archiver-form.html');

        $this->assertStringContainsString('dlcheck', $html, 'ExClient::buttonPress looks for "dlcheck" before posting');
        $this->assertFalse((new \ExPage_Archiver($html))->getContinueUrl());
    }

    public function testContinuePage(): void
    {
        $url = (new \ExPage_Archiver(Fixtures::html('archiver-continue.html')))->getContinueUrl();

        $this->assertIsString($url);
        $this->assertMatchesRegularExpression('~^https?://~', $url);
    }

    public function testInsufficientCredits(): void
    {
        $this->assertFalse((new \ExPage_Archiver('<p>Insufficient Credits.</p>'))->getContinueUrl());
    }
}
