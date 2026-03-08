# TMGMT Phrase TMS (tmgmt_memsource)

TMGMT Phrase TMS module is a plugin for the Translation Management Tool module (tmgmt).
It uses the Phrase TMS (https://www.phrase.com) for automated translation of content, including file attachments.

## REQUIREMENTS

This module requires TMGMT (http://drupal.org/project/tmgmt) module to be installed.

You will need to enter your Phrase TMS credentials, which you can obtain by registering on https://www.phrase.com.

## FEATURES

### File Translation

The module supports translating file attachments along with content. Supported file types include:

- Microsoft Office formats (DOCX, XLSX, PPTX, DOC, XLS, PPT)

#### Configuration

1. Configure allowed MIME types in TMGMT settings (`/admin/tmgmt/settings`).
2. Enable file translation in the Phrase TMS translator settings.

#### How it works

1. When content with file attachments is sent for translation, the files are uploaded to Phrase TMS.
2. When translations are pulled back, translated files are downloaded and saved with a language suffix (e.g., `document_de.docx`).
3. The translated files are automatically associated with the translated content.

## RELEASE NOTES

For official release notes, see:
https://support.phrase.com/hc/en-us/articles/5743706903964-Plugin-Releases
