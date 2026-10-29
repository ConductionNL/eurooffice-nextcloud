<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH or a Nextcloud affiliate company and Euro-Office contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Eurooffice\Tests\Unit\Conversion;

use Exception;
use OCA\Eurooffice\AppConfig;
use OCA\Eurooffice\Conversion\ConversionProvider;
use OCA\Eurooffice\Crypt;
use OCA\Eurooffice\DocumentService;
use OCA\Eurooffice\FileUtility;
use OCP\Files\Conversion\ConversionMimeProvider;
use OCP\Files\File;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConversionProvider::class)]
class ConversionProviderTest extends TestCase {

    private const DOCX = "application/vnd.openxmlformats-officedocument.wordprocessingml.document";
    private const ODT = "application/vnd.oasis.opendocument.text";

    private AppConfig&Stub $appConfig;
    private DocumentService&MockObject $documentService;
    private Crypt&MockObject $crypt;
    private IURLGenerator&Stub $urlGenerator;
    private ConversionProvider $provider;

    protected function setUp(): void {
        $this->appConfig = $this->createStub(AppConfig::class);
        $this->appConfig->method("getDocumentServerInternalUrl")->willReturn("http://eurooffice/");
        $this->appConfig->method("useDemo")->willReturn(false);
        $this->appConfig->method("getStorageUrl")->willReturn("");

        $this->documentService = $this->createMock(DocumentService::class);
        $this->crypt = $this->createMock(Crypt::class);

        $this->urlGenerator = $this->createStub(IURLGenerator::class);
        $this->urlGenerator->method("linkToRouteAbsolute")
            ->willReturnCallback(static fn (string $route, array $params): string => "https://cloud.example/apps/eurooffice/download?doc=" . $params["doc"]);
        $this->urlGenerator->method("getAbsoluteURL")->willReturn("https://cloud.example/");

        $fileUtility = $this->createStub(FileUtility::class);
        $fileUtility->method("getKey")->willReturn("filekey");

        $trans = $this->createStub(IL10N::class);
        $trans->method("t")->willReturnArgument(0);
        $trans->method("getLocaleCode")->willReturn("nl_NL");

        $this->provider = new ConversionProvider(
            "eurooffice",
            $this->appConfig,
            $this->documentService,
            $this->crypt,
            $fileUtility,
            $this->urlGenerator,
            $trans,
        );
    }

    private function file(string $mimeType, string $path = "/alice/files/report.docx"): File&Stub {
        $file = $this->createStub(File::class);
        $file->method("getMimetype")->willReturn($mimeType);
        $file->method("getPath")->willReturn($path);
        $file->method("getId")->willReturn(42);

        return $file;
    }

    /**
     * Without a document server address the provider must not offer
     * anything, so IConversionManager can fall back to another provider.
     */
    public function testNoConversionsWhenDocumentServerIsNotConfigured(): void {
        $appConfig = $this->createStub(AppConfig::class);
        $appConfig->method("getDocumentServerInternalUrl")->willReturn("");

        $provider = new ConversionProvider(
            "eurooffice",
            $appConfig,
            $this->createStub(DocumentService::class),
            $this->createStub(Crypt::class),
            $this->createStub(FileUtility::class),
            $this->createStub(IURLGenerator::class),
            $this->createStub(IL10N::class),
        );

        $this->assertSame([], $provider->getSupportedMimeTypes());
    }

    public static function supportedConversionProvider(): array {
        return [
            "docx to pdf" => [self::DOCX, "application/pdf", "pdf"],
            "odt to pdf" => [self::ODT, "application/pdf", "pdf"],
            "html to pdf" => ["text/html", "application/pdf", "pdf"],
            "markdown to pdf" => ["text/markdown", "application/pdf", "pdf"],
            "odt to docx" => [self::ODT, self::DOCX, "docx"],
            "docx to odt" => [self::DOCX, self::ODT, "odt"],
            "docx to markdown" => [self::DOCX, "text/markdown", "md"],
            "csv to xlsx" => ["text/csv", "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet", "xlsx"],
            "pptx to odp" => ["application/vnd.openxmlformats-officedocument.presentationml.presentation", "application/vnd.oasis.opendocument.presentation", "odp"],
        ];
    }

    #[DataProvider("supportedConversionProvider")]
    public function testSupportedMimeTypesContainConversion(string $from, string $to, string $extension): void {
        $matches = array_filter(
            $this->provider->getSupportedMimeTypes(),
            static fn (ConversionMimeProvider $mime): bool => $mime->getFrom() === $from && $mime->getTo() === $to,
        );

        $this->assertCount(1, $matches);
        $this->assertSame($extension, array_values($matches)[0]->getExtension());
    }

    public function testSupportedMimeTypesHaveNoDuplicatesOrIdentityConversions(): void {
        $pairs = array_map(
            static fn (ConversionMimeProvider $mime): string => $mime->getFrom() . " > " . $mime->getTo(),
            $this->provider->getSupportedMimeTypes(),
        );

        $this->assertSame($pairs, array_values(array_unique($pairs)));
        foreach ($this->provider->getSupportedMimeTypes() as $mime) {
            $this->assertNotSame($mime->getFrom(), $mime->getTo());
        }
    }

    public function testConvertFileSignsDownloadLinkAndReturnsConvertedContent(): void {
        $this->appConfig->method("isUserAllowedToUse")->willReturn(true);

        $this->crypt->expects($this->once())
            ->method("getHash")
            ->with(["action" => "download", "fileId" => 42, "userId" => "alice"])
            ->willReturn("signed");

        $this->documentService->expects($this->once())
            ->method("getConvertedUri")
            ->with(
                "https://cloud.example/apps/eurooffice/download?doc=signed",
                "docx",
                "pdf",
                $this->anything(),
                "nl-NL"
            )
            ->willReturn("http://eurooffice/cache/result.pdf");
        $this->documentService->expects($this->once())
            ->method("request")
            ->with("http://eurooffice/cache/result.pdf")
            ->willReturn("%PDF-1.7");

        $this->assertSame("%PDF-1.7", $this->provider->convertFile($this->file(self::DOCX), "application/pdf"));
    }

    /**
     * The document server fetches the source through the internal storage
     * address when one is configured.
     */
    public function testConvertFileUsesStorageUrl(): void {
        $appConfig = $this->createStub(AppConfig::class);
        $appConfig->method("getDocumentServerInternalUrl")->willReturn("http://eurooffice/");
        $appConfig->method("useDemo")->willReturn(false);
        $appConfig->method("getStorageUrl")->willReturn("http://nextcloud/");
        $appConfig->method("isUserAllowedToUse")->willReturn(true);

        $crypt = $this->createStub(Crypt::class);
        $crypt->method("getHash")->willReturn("signed");

        $this->documentService->expects($this->once())
            ->method("getConvertedUri")
            ->with("http://nextcloud/apps/eurooffice/download?doc=signed", "odt", "docx")
            ->willReturn("http://eurooffice/cache/result.docx");
        $this->documentService->method("request")->willReturn("docx-bytes");

        $provider = new ConversionProvider(
            "eurooffice",
            $appConfig,
            $this->documentService,
            $crypt,
            $this->createStub(FileUtility::class),
            $this->urlGenerator,
            $this->createStub(IL10N::class),
        );

        $this->assertSame("docx-bytes", $provider->convertFile($this->file(self::ODT, "/alice/files/a.odt"), self::DOCX));
    }

    /**
     * Outside a user's files folder the download link is signed for the owner.
     */
    public function testConvertFileFallsBackToOwner(): void {
        $this->appConfig->method("isUserAllowedToUse")->willReturn(true);

        $owner = $this->createStub(IUser::class);
        $owner->method("getUID")->willReturn("bob");
        $file = $this->createStub(File::class);
        $file->method("getMimetype")->willReturn(self::DOCX);
        $file->method("getPath")->willReturn("/__groupfolders/1/report.docx");
        $file->method("getId")->willReturn(7);
        $file->method("getOwner")->willReturn($owner);

        $this->crypt->expects($this->once())
            ->method("getHash")
            ->with(["action" => "download", "fileId" => 7, "userId" => "bob"])
            ->willReturn("signed");
        $this->documentService->method("getConvertedUri")->willReturn("http://eurooffice/cache/result.pdf");
        $this->documentService->method("request")->willReturn("%PDF");

        $this->assertSame("%PDF", $this->provider->convertFile($file, "application/pdf"));
    }

    public static function rejectedConversionProvider(): array {
        return [
            "unknown target" => [self::DOCX, "image/png"],
            "unknown source" => ["image/jpeg", "application/pdf"],
            "pair not offered" => ["text/csv", self::DOCX],
        ];
    }

    /**
     * Unsupported conversions fail before anything is sent to the document server.
     */
    #[DataProvider("rejectedConversionProvider")]
    public function testConvertFileRejectsUnsupportedConversion(string $from, string $to): void {
        $this->documentService->expects($this->never())->method("getConvertedUri");

        $this->expectException(Exception::class);

        $this->provider->convertFile($this->file($from), $to);
    }

    /**
     * Users outside the app's allowed groups cannot convert through it;
     * the check runs before the source is exposed to the document server.
     */
    public function testConvertFileRejectsUserOutsideAllowedGroups(): void {
        $this->appConfig->method("isUserAllowedToUse")->willReturn(false);

        $this->crypt->expects($this->never())->method("getHash");
        $this->documentService->expects($this->never())->method("getConvertedUri");

        $this->expectException(Exception::class);

        $this->provider->convertFile($this->file(self::DOCX), "application/pdf");
    }

    public function testConvertFileRejectsFileWithoutUser(): void {
        $file = $this->createStub(File::class);
        $file->method("getMimetype")->willReturn(self::DOCX);
        $file->method("getPath")->willReturn("/appdata_x/report.docx");
        $file->method("getOwner")->willReturn(null);

        $this->documentService->expects($this->never())->method("getConvertedUri");

        $this->expectException(Exception::class);

        $this->provider->convertFile($file, "application/pdf");
    }
}
