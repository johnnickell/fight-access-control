"""Allowlist repository content without excluding Material's own files."""

from posixpath import normpath
from urllib.parse import unquote, urlsplit

from mkdocs.exceptions import PluginError

CONSUMER_FILES = {
    "index.md",
    "getting-started.md",
    "capabilities.md",
    "users.md",
    "frameworks.md",
    "assets/fight-access-control-dark.svg",
    "assets/consumer-site.css",
}


def validate_asset_references(setting, references, candidates):
    emitted = {file.dest_uri for file in candidates if file.inclusion.is_included()}
    for reference in references:
        # MkDocs accepts both strings and structured entries in extra_javascript.
        url = urlsplit(str(reference))
        if url.scheme or url.netloc:
            continue

        # Local assets must resolve when the site is served from a subdirectory.
        path = unquote(url.path)
        if path.startswith('/') or path.endswith('/') or normpath(path) not in emitted:
            raise PluginError(f"{setting} must reference an emitted asset: {str(reference)!r}")


def on_files(files, *, config):
    found = set()
    for file in list(files):
        if file.src_dir != config.docs_dir:
            continue
        if file.src_uri in CONSUMER_FILES:
            found.add(file.src_uri)
        else:
            files.remove(file)

    missing = CONSUMER_FILES - found
    if missing:
        raise PluginError(f"Missing consumer files: {', '.join(sorted(missing))}")

    # Restore theme files that an excluded repository file might have shadowed.
    files.add_files_from_theme(config.theme.get_env(), config)

    validate_asset_references('extra_css', config.extra_css, files.css_files())
    validate_asset_references('extra_javascript', config.extra_javascript, files.javascript_files())
    if logo := config.theme.get('logo'):
        validate_asset_references('theme.logo', [logo], files.media_files())
    validate_asset_references('theme.favicon', [config.theme.get('favicon')], files.media_files())

    return files
