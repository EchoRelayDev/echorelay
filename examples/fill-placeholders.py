#!/usr/bin/env python3
"""Fill PLACEHOLDER tokens in an endpoint document from the environment.

Text substitution cannot do this job safely: a value holding `&`, a quote or a
newline either corrupts the JSON or, with sed, is re-expanded as part of the
pattern. Target URLs carry query strings and sender names carry ampersands, so
both happen in ordinary use. This walks the decoded document instead and
replaces tokens inside string values, then re-encodes.

Usage: fill-placeholders.py <file.json> TOKEN=ENV_VAR [TOKEN=ENV_VAR ...]
"""
import json
import os
import sys


def fill(node, replacements):
    if isinstance(node, str):
        for token, value in replacements.items():
            node = node.replace(token, value)
        return node
    if isinstance(node, list):
        return [fill(item, replacements) for item in node]
    if isinstance(node, dict):
        return {key: fill(value, replacements) for key, value in node.items()}
    return node


def main():
    if len(sys.argv) < 3:
        sys.exit(__doc__)

    replacements = {}
    for pair in sys.argv[2:]:
        token, _, name = pair.partition("=")
        value = os.environ.get(name)
        if value is None:
            sys.exit(f"fill-placeholders: {name} is not set")
        replacements[token] = value

    with open(sys.argv[1], encoding="utf-8") as handle:
        document = json.load(handle)

    json.dump(fill(document, replacements), sys.stdout)
    sys.stdout.write("\n")


if __name__ == "__main__":
    main()
