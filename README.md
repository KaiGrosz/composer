<p align="center">
    <a href="https://getcomposer.org">
        <img src="https://getcomposer.org/img/logo-composer-transparent.png" alt="Composer">
    </a>
</p>
<h1 align="center">Dependency Management for PHP</h1>

Composer helps you declare, manage, and install dependencies of PHP projects.

See [https://getcomposer.org/](https://getcomposer.org/) for more information and documentation.

---

## FAIR fork

This is a fork of Composer with the **FAIR protocol** built directly into the core — no plugin required.

### Why a fork?

The [fair-handler plugin](https://github.com/kaigrosz/fair-handler) works for users on stock Composer, but a plugin has limits: it cannot register new dist types, hook into the download pipeline as deeply, or ship async DID resolution via `Loop::wait()`. Baking FAIR into Composer core removes all of those constraints.

This fork is the proposal and proof-of-concept for upstreaming FAIR into Composer proper.

### What changed

**New files:**

| File | Purpose |
|---|---|
| `src/Composer/Util/Fair/DidDocument.php` | Parses a W3C DID document; extracts service endpoint and Ed25519 signing keys |
| `src/Composer/Util/Fair/PlcDidResolver.php` | Resolves `did:plc:` DIDs via [plc.directory](https://plc.directory/) |
| `src/Composer/Util/Fair/WebDidResolver.php` | Resolves `did:web:` DIDs via HTTPS `did.json` (W3C spec) |
| `src/Composer/Util/Fair/DidResolver.php` | Dispatcher — routes any DID to the right resolver; exposes `isSupported()` |
| `src/Composer/Util/Fair/MetadataDocument.php` | Parses the FAIR package metadata document |
| `src/Composer/Util/Fair/ReleaseDocument.php` | Parses a single release entry (artifacts, requires, signatures) |
| `src/Composer/Util/Fair/MetadataFetcher.php` | Fetches metadata from a service endpoint; supports async via `addRequest()` |
| `src/Composer/Util/Fair/PackageFactory.php` | Converts FAIR metadata into `CompletePackage` objects |
| `src/Composer/Util/Fair/SignatureVerifier.php` | SHA-256 checksum + Ed25519 signature verification |
| `src/Composer/Util/Fair/KeyDecoder.php` | Decodes Multibase-encoded Ed25519 public keys |
| `src/Composer/Util/Fair/Cache.php` | Disk cache for DID documents and metadata (keyed under `cache-dir/fair/`) |
| `src/Composer/Repository/FairRepository.php` | `type: fair` repository — resolves DIDs and builds packages concurrently via `Loop::wait()` |
| `src/Composer/Downloader/FairDownloader.php` | Download handler — verifies checksum and Ed25519 signature before extraction |
| `src/Composer/Command/FairRequireCommand.php` | `composer fair-require <did>` — resolves DID, updates `composer.json`, runs install |

**Modified files:**

- `src/Composer/Repository/RepositoryFactory.php` — registers `fair` as a built-in repository type
- `src/Composer/Downloader/DownloadManager.php` — registers `FairDownloader` for the `fair-zip` dist type

### Building the PHAR

```bash
php bin/compile
```

Requires PHP 8.2+, `ext-sodium`, `ext-gmp`.

[![Continuous Integration](https://github.com/composer/composer/actions/workflows/continuous-integration.yml/badge.svg?branch=main)](https://github.com/composer/composer/actions/workflows/continuous-integration.yml?query=branch%3Amain)

Installation / Usage
--------------------

Download and install Composer by following the [official instructions](https://getcomposer.org/download/).

For usage, see [the documentation](https://getcomposer.org/doc/).

Packages
--------

Find public packages on [Packagist.org](https://packagist.org).

For private package hosting take a look at [Private Packagist](https://packagist.com).

Community
---------

Follow [@packagist](https://twitter.com/packagist) or [@seldaek](https://twitter.com/seldaek) on Twitter for announcements, or check the [#composerphp](https://twitter.com/search?q=%23composerphp&src=typed_query&f=live) hashtag.

For support, Stack Overflow offers a good collection of
[Composer related questions](https://stackoverflow.com/questions/tagged/composer-php), or you can use the [GitHub discussions](https://github.com/composer/composer/discussions).

Please note that this project is released with a
[Contributor Code of Conduct](https://www.contributor-covenant.org/version/1/4/code-of-conduct/).
By participating in this project and its community you agree to abide by those terms.

Requirements
------------

#### Latest Composer

PHP 7.2.5 or above for the latest version.

#### Composer 2.2 LTS (Long Term Support)

PHP versions 5.3.2 - 8.1 are still supported via the LTS releases of Composer (2.2.x). If you
run the installer or the `self-update` command the appropriate Composer version for your PHP
should be automatically selected.

#### Binary dependencies

- `unzip` (or `7z`/`7zz`)
- `gzip`
- `tar`
- `unrar`
- `xz`
- Git (`git`)
- Mercurial (`hg`)
- Fossil (`fossil`)
- Perforce (`p4`)
- Subversion (`svn`)

The need for these binary dependencies may vary depending on individual use cases. For most users,
only 2 dependencies are essential for Composer: `unzip` (or `7z`/`7zz`), and `git`. If the
[`ext-zip`](https://www.php.net/manual/en/zip.installation.php) extension is available, only `git`
is needed, but this is not recommended.

Authors
-------

- Nils Adermann  | [GitHub](https://github.com/naderman)  | [Twitter](https://twitter.com/naderman) | <naderman@naderman.de> | [naderman.de](https://naderman.de)
- Jordi Boggiano | [GitHub](https://github.com/Seldaek) | [Twitter](https://twitter.com/seldaek) | <j.boggiano@seld.be> | [seld.be](https://seld.be)

See also the list of [contributors](https://github.com/composer/composer/contributors) who participated in this project.

Security Reports
----------------

Please send any sensitive issue to [security@packagist.org](mailto:security@packagist.org). Thanks!

License
-------

Composer is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

Acknowledgments
---------------

- This project's Solver started out as a PHP port of openSUSE's
  [Libzypp satsolver](https://en.opensuse.org/openSUSE:Libzypp_satsolver).
