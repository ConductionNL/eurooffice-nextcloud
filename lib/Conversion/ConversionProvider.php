<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH or a Nextcloud affiliate company and Euro-Office contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Eurooffice\Conversion;

use OCA\Eurooffice\AppConfig;
use OCA\Eurooffice\Crypt;
use OCA\Eurooffice\DocumentService;
use OCA\Eurooffice\FileUtility;
use OCP\App\IAppManager;
use OCP\Files\Conversion\ConversionMimeProvider;
use OCP\Files\Conversion\IConversionProvider;
use OCP\Files\File;
use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * Converts files for Nextcloud's IConversionManager through the document server
 *
 * @package OCA\Eurooffice\Conversion
 */
class ConversionProvider implements IConversionProvider {

    /**
     * Input formats by extension
     */
    private const SOURCE_MIME_TYPES = [
        "doc" => "application/msword",
        "docx" => "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
        "dotx" => "application/vnd.openxmlformats-officedocument.wordprocessingml.template",
        "odt" => "application/vnd.oasis.opendocument.text",
        "ott" => "application/vnd.oasis.opendocument.text-template",
        "rtf" => "text/rtf",
        "html" => "text/html",
        "md" => "text/markdown",
        "txt" => "text/plain",
        "xls" => "application/vnd.ms-excel",
        "xlsx" => "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
        "ods" => "application/vnd.oasis.opendocument.spreadsheet",
        "csv" => "text/csv",
        "ppt" => "application/vnd.ms-powerpoint",
        "pptx" => "application/vnd.openxmlformats-officedocument.presentationml.presentation",
        "odp" => "application/vnd.oasis.opendocument.presentation",
    ];

    /**
     * Output extension => source extensions the document server converts from
     */
    private const CONVERSIONS = [
        "pdf" => ["doc", "docx", "dotx", "odt", "ott", "rtf", "html", "md", "txt", "xls", "xlsx", "ods", "csv", "ppt", "pptx", "odp"],
        "docx" => ["doc", "dotx", "odt", "ott", "rtf", "html", "md"],
        "odt" => ["doc", "docx", "dotx", "ott", "rtf", "html", "md"],
        "rtf" => ["doc", "docx", "odt"],
        "txt" => ["doc", "docx", "odt"],
        "md" => ["doc", "docx", "odt"],
        "xlsx" => ["xls", "ods", "csv"],
        "ods" => ["xls", "xlsx", "csv"],
        "csv" => ["xls", "xlsx", "ods"],
        "pptx" => ["ppt", "odp"],
        "odp" => ["ppt", "pptx"],
    ];

    public function __construct(
        private readonly string $appName,
        private readonly AppConfig $appConfig,
        private readonly DocumentService $documentService,
        private readonly Crypt $crypt,
        private readonly FileUtility $fileUtility,
        private readonly IURLGenerator $urlGenerator,
        private readonly IL10N $trans,
        private readonly IAppManager $appManager,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function getSupportedMimeTypes(): array {
        if (empty($this->appConfig->getDocumentServerInternalUrl())) {
            return [];
        }

        $targets = $this->getTargets();

        $providers = [];
        foreach (self::CONVERSIONS as $toExtension => $fromExtensions) {
            foreach ($fromExtensions as $fromExtension) {
                $providers[] = new ConversionMimeProvider(
                    self::SOURCE_MIME_TYPES[$fromExtension],
                    $targets[$toExtension]["mime"],
                    $toExtension,
                    $targets[$toExtension]["name"]
                );
            }
        }

        return $providers;
    }

    /**
     * {@inheritDoc}
     *
     * @return string content of the converted file
     */
    public function convertFile(File $file, string $targetMimeType): mixed {
        $toExtension = $this->getExtensionForTarget($targetMimeType);
        $fromExtension = $this->getExtensionForSource($file);
        if ($toExtension === null || $fromExtension === null
            || !in_array($fromExtension, self::CONVERSIONS[$toExtension], true)) {
            throw new \Exception($this->trans->t("Conversion is not supported for this file type"));
        }

        $userId = $this->getUserId($file);
        if ($userId === null) {
            throw new \Exception($this->trans->t("Conversion is unavailable for this file."));
        }
        if (!$this->appConfig->isUserAllowedToUse($userId)) {
            throw new \Exception($this->trans->t("Not permitted"));
        }

        $hashUrl = $this->crypt->getHash([
            "action" => "download",
            "fileId" => $file->getId(),
            "userId" => $userId,
        ]);
        // Routes of an app are only generated once the app is loaded, which CLI and background jobs do not guarantee
        $this->appManager->loadApp($this->appName);
        $fileUrl = $this->urlGenerator->linkToRouteAbsolute($this->appName . ".callback.download", ["doc" => $hashUrl]);
        if (!str_contains($fileUrl, "/apps/" . $this->appName . "/download?")) {
            throw new \Exception($this->trans->t("Conversion is unavailable for this file."));
        }
        if (!$this->appConfig->useDemo() && !empty($this->appConfig->getStorageUrl())) {
            $fileUrl = str_replace($this->urlGenerator->getAbsoluteURL("/"), $this->appConfig->getStorageUrl(), $fileUrl);
        }

        $key = DocumentService::generateRevisionId("conv_" . $toExtension . "_" . $this->fileUtility->getKey($file));
        $region = str_replace("_", "-", $this->trans->getLocaleCode());

        $convertedUrl = $this->documentService->getConvertedUri($fileUrl, $fromExtension, $toExtension, $key, $region);

        $content = $this->documentService->request($convertedUrl);
        if (!is_string($content) || !$this->isExpectedContent($content, $toExtension)) {
            throw new \Exception($this->trans->t("The document server returned an unexpected result"));
        }

        return $content;
    }

    /**
     * Whether the converted content matches the requested format, so an error
     * or login page is never stored as the converted file
     */
    private function isExpectedContent(string $content, string $toExtension): bool {
        if ($content === "") {
            return false;
        }

        return match ($toExtension) {
            "pdf" => str_starts_with($content, "%PDF-"),
            "docx", "odt", "xlsx", "ods", "pptx", "odp" => str_starts_with($content, "PK\x03\x04"),
            "rtf" => str_starts_with($content, "{\\rtf"),
            default => preg_match("/^\\s*<(!doctype\\s+html|html[\\s>])/i", $content) !== 1,
        };
    }

    /**
     * User whose files folder holds the node, so the download callback can resolve it by id
     */
    private function getUserId(File $file): ?string {
        if (preg_match("#^/([^/]+)/files/#", $file->getPath(), $matches) === 1) {
            return $matches[1];
        }

        return $file->getOwner()?->getUID();
    }

    /**
     * Output formats by extension, with MIME type and display name
     *
     * @return array<string, array{mime: string, name: string}>
     */
    private function getTargets(): array {
        return [
            "pdf" => ["mime" => "application/pdf", "name" => $this->trans->t("PDF (.pdf)")],
            "docx" => ["mime" => self::SOURCE_MIME_TYPES["docx"], "name" => $this->trans->t("Word Document (.docx)")],
            "odt" => ["mime" => self::SOURCE_MIME_TYPES["odt"], "name" => $this->trans->t("OpenDocument Text (.odt)")],
            "rtf" => ["mime" => self::SOURCE_MIME_TYPES["rtf"], "name" => $this->trans->t("Rich Text (.rtf)")],
            "txt" => ["mime" => self::SOURCE_MIME_TYPES["txt"], "name" => $this->trans->t("Text (.txt)")],
            "md" => ["mime" => self::SOURCE_MIME_TYPES["md"], "name" => $this->trans->t("Markdown (.md)")],
            "xlsx" => ["mime" => self::SOURCE_MIME_TYPES["xlsx"], "name" => $this->trans->t("Excel Workbook (.xlsx)")],
            "ods" => ["mime" => self::SOURCE_MIME_TYPES["ods"], "name" => $this->trans->t("OpenDocument Spreadsheet (.ods)")],
            "csv" => ["mime" => self::SOURCE_MIME_TYPES["csv"], "name" => $this->trans->t("Comma-separated values (.csv)")],
            "pptx" => ["mime" => self::SOURCE_MIME_TYPES["pptx"], "name" => $this->trans->t("PowerPoint Presentation (.pptx)")],
            "odp" => ["mime" => self::SOURCE_MIME_TYPES["odp"], "name" => $this->trans->t("OpenDocument Presentation (.odp)")],
        ];
    }

    private function getExtensionForTarget(string $targetMimeType): ?string {
        foreach ($this->getTargets() as $extension => $target) {
            if ($target["mime"] === $targetMimeType) {
                return $extension;
            }
        }

        return null;
    }

    /**
     * Source extension from the file's MIME type, which is what IConversionManager matched on
     */
    private function getExtensionForSource(File $file): ?string {
        $extension = array_search($file->getMimetype(), self::SOURCE_MIME_TYPES, true);

        return $extension === false ? null : $extension;
    }
}
