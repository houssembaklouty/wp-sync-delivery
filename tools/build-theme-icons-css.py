#!/usr/bin/env python3
"""Thème WPArabicEcommCOD : remplace les glyphes Font Awesome des pseudo-éléments CSS (::before / ::after) par des
icônes Heroicons (masques CSS). Lit style.css et rtl.css, génère assets/css/icons.css.
Usage : python3 tools/build-theme-icons-css.py /tmp/theme-work /tmp/hi
"""
import re, sys, glob, urllib.parse
T = sys.argv[1]; HI = glob.glob(sys.argv[2] + '/heroicons-*/package')[0]
CODES = {'f105': ('chevron-right', 'o'), 'f107': ('chevron-down', 'o'), 'f058': ('check-circle', 'o'), 'f061': ('arrow-right', 'o'),
         'f110': ('arrow-path', 'o'), 'f104': ('chevron-left', 'o'), 'f0d9': ('chevron-left', 'o'), 'f05a': ('information-circle', 'o'), 'f35d': ('arrow-top-right-on-square', 'o'), 'f0c9': ('bars-3', 'o')}

def uri(name, style):
    f = HI + ('/24/solid/' if style == 's' else '/24/outline/') + name + '.svg'
    s = open(f).read().replace('currentColor', '#000').replace('data-slot="icon"', '').replace('aria-hidden="true"', '')
    s = re.sub(r'\s+', ' ', s).strip()
    return 'url("data:image/svg+xml,' + urllib.parse.quote(s, safe="/:=' ,.-") + '")'

def blocks(css):
    """Règles feuilles (sélecteur, corps, enveloppes @media)."""
    out = []; i = 0; stack = []; start = 0
    while i < len(css):
        c = css[i]
        if c == '{':
            head = css[start:i].strip()
            stack.append((head, i + 1)); start = i + 1
        elif c == '}':
            head, bstart = stack.pop()
            body = css[bstart:i]
            if '{' not in body:
                wraps = [h for h, _ in stack if h.startswith('@')]
                out.append((head, body, wraps))
            start = i + 1
        i += 1
    return out

rules = []
for f in ('style.css', 'rtl.css'):
    for sel, body, wraps in blocks(open(T + '/' + f).read()):
        if 'content' not in body:
            continue
        m = re.search(r'content:\s*"((?:\\f[0-9a-f]{3})+)"', body)
        if not m:
            continue
        codes = re.findall(r'\\(f[0-9a-f]{3})', m.group(1))
        if codes[0] not in CODES and codes[0] != 'f005':
            print('code ignoré', codes[0], sel[:60]); continue
        weight = re.search(r'font-weight:\s*(\d+)', body)
        solid = weight and weight.group(1) == '900'
        rules.append((sel, codes, solid, wraps))

css = ['/* Icônes Heroicons (MIT) en masques CSS — remplace les glyphes Font Awesome des pseudo-éléments.',
       '   Généré par tools/build-theme-icons-css.py : ne pas modifier à la main. */',
       '.hi { width: 1.15em; height: 1.15em; vertical-align: -.2em; flex: none; display: inline-block; }',
       'svg.hi { fill: none; }', 'svg.hi-brand { fill: currentColor; }']
seen = set()
for sel, codes, solid, wraps in rules:
    key = (sel, tuple(codes), solid, tuple(wraps))
    if key in seen:
        continue
    seen.add(key)
    code = codes[0]
    if code == 'f005':
        name, st = 'star', ('s' if solid else 'o')
    else:
        name, st = CODES.get(code, ('chevron-right', 'o'))
    n = len(codes)
    m = uri(name, st)
    decl = ('font-family: inherit !important; font-weight: normal !important; content: "" !important; '
            'background-color: currentColor; -webkit-mask: %s center / %s no-repeat; mask: %s center / %s no-repeat; ' %
            (m, '1.125em 1em' if n > 1 else 'contain', m, '1.125em 1em' if n > 1 else 'contain'))
    if code == 'f005':
        decl += 'width: %.3fem; height: 1em; -webkit-mask-repeat: repeat-x; mask-repeat: repeat-x; -webkit-mask-position: left center; mask-position: left center; ' % (1.125 * n)
    else:
        decl += 'width: 1em; height: 1em; '
    rule = '%s { %s}' % (sel, decl)
    for w in reversed(wraps):
        rule = '%s { %s }' % (w, rule)
    css.append(rule)
css.append('/* Les chevrons sont écrits pour le RTL. */')
open(T + '/assets/css/icons.css', 'w').write('\n'.join(css) + '\n')
print(len(seen), 'règles générées')
