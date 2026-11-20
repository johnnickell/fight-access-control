"""Allowlist repository content without excluding Material's own files."""

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
    return files
