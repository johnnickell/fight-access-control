"""Qualify the exact live GitHub publication before local source/ref disposal"""
import re
from .resources import run, query, require, text


def published(doc, oid, remote_ref, pr):
    root = doc['safe']
    require(remote_ref == doc['ref'], 'exact owned full remote ref required')
    origin = text(['git', 'remote', 'get-url', 'origin'], root)
    match = re.fullmatch(r'(?:git@github\.com:|https://github\.com/)([^/]+/[^/]+?)(?:\.git)?', origin)
    require(match is not None, 'supported exact GitHub origin required for publication proof')
    require(re.fullmatch(r'https://github\.com/' + re.escape(match[1]) + r'/pull/[1-9]\d*', pr or ''), 'exact origin-owned PR required')
    proof = query(['gh', 'pr', 'view', pr, '--json', 'url,state,isDraft,headRefName,headRefOid,baseRefName,mergedAt,mergeCommit'], root)
    require(proof['url'] == pr and proof['headRefOid'] == oid and
            proof['headRefName'] == remote_ref.removeprefix('refs/heads/') and proof['baseRefName'] == 'develop',
            'live PR repository/base/head identity mismatch')
    if proof['state'] == 'OPEN':
        require(not proof['isDraft'], 'draft evidence publication is not delivery completion')
        require(text(['git', 'ls-remote', '--exit-code', 'origin', remote_ref], root).split() == [oid, remote_ref],
                'published source unavailable or changed')
    else:
        require(proof['state'] == 'MERGED' and proof['mergedAt'] and proof['mergeCommit']['oid'], 'PR not delivered; preserve local source')
        run(['git', 'merge-base', '--is-ancestor', proof['mergeCommit']['oid'], 'refs/remotes/origin/' + proof['baseRefName']], root)
    return proof
