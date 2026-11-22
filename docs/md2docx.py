"""Convertit docs/GUIDE.*.md en .docx (images et GIF animés inclus).

Usage : python docs/md2docx.py docs/GUIDE.fr.md docs/GUIDE.fr.docx
Prend en charge le sous-ensemble de Markdown utilisé par les guides :
titres #/##/###, paragraphes, listes à puces et numérotées (2 niveaux),
blocs de code, tableaux, images, **gras**, *italique*, `code` et liens.
Dépendance : pip install python-docx
"""
import copy
import os
import re
import sys

from docx import Document
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.image.image import Image as DocxImage
from docx.oxml import OxmlElement
from docx.oxml.ns import qn
from docx.shared import Inches, Pt, RGBColor

REPO = 'https://github.com/banshee1971/iGate-APRS-avec-un-Raspberry-Pi-Zero-2W/blob/main/docs/'
MAX_WIDTH_IN = 6.5
FONT = 'Calibri'
CODE_FONT = 'Consolas'
LINK_COLOR = '1F5FBF'


def shade(element_pr, fill):
    shd = OxmlElement('w:shd')
    shd.set(qn('w:val'), 'clear')
    shd.set(qn('w:color'), 'auto')
    shd.set(qn('w:fill'), fill)
    element_pr.append(shd)


def add_hyperlink(par, url, text, bold=False, italic=False, code=False):
    rel = par.part.relate_to(url, 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink',
                             is_external=True)
    link = OxmlElement('w:hyperlink')
    link.set(qn('r:id'), rel)
    run = OxmlElement('w:r')
    rpr = OxmlElement('w:rPr')
    color = OxmlElement('w:color')
    color.set(qn('w:val'), LINK_COLOR)
    rpr.append(color)
    u = OxmlElement('w:u')
    u.set(qn('w:val'), 'single')
    rpr.append(u)
    if bold:
        rpr.append(OxmlElement('w:b'))
    if italic:
        rpr.append(OxmlElement('w:i'))
    if code:
        fonts = OxmlElement('w:rFonts')
        for a in ('w:ascii', 'w:hAnsi', 'w:cs'):
            fonts.set(qn(a), CODE_FONT)
        rpr.append(fonts)
    run.append(rpr)
    t = OxmlElement('w:t')
    t.text = text
    t.set(qn('xml:space'), 'preserve')
    run.append(t)
    link.append(run)
    par._p.append(link)


INLINE = re.compile(r'(\*\*[^*]+\*\*|\*[^*]+\*|`[^`]+`|\[[^\]]+\]\([^)]+\)|https?://[^\s)]+)')


def fix_url(url):
    if not re.match(r'https?://', url) and url.endswith('.md'):
        return REPO + url
    return url


def add_inline(par, text, bold=False):
    for part in INLINE.split(text):
        if not part:
            continue
        if part.startswith('**') and part.endswith('**'):
            add_inline(par, part[2:-2], bold=True)
        elif part.startswith('`') and part.endswith('`'):
            r = par.add_run(part[1:-1])
            r.font.name = CODE_FONT
            r.bold = bold
        elif part.startswith('[') and '](' in part:
            label, url = re.match(r'\[([^\]]+)\]\(([^)]+)\)', part).groups()
            add_hyperlink(par, fix_url(url), label, bold=bold)
        elif re.match(r'https?://', part):
            trail = ''
            while part and part[-1] in '.,;:':
                trail = part[-1] + trail
                part = part[:-1]
            add_hyperlink(par, part, part, bold=bold)
            if trail:
                par.add_run(trail).bold = bold
        elif part.startswith('*') and part.endswith('*') and len(part) > 2:
            r = par.add_run(part[1:-1])
            r.italic = True
            r.bold = bold
        else:
            par.add_run(part).bold = bold


class Numbering:
    """Recommence la numérotation à 1 pour chaque nouvelle liste numérotée."""

    def __init__(self, doc):
        self.doc = doc
        self.numbering = doc.part.numbering_part.element
        style = doc.styles['List Number']
        self.base_num_id = int(style.element.pPr.numPr.numId.val)
        self.abstract_id = None
        for num in self.numbering.findall(qn('w:num')):
            if int(num.get(qn('w:numId'))) == self.base_num_id:
                self.abstract_id = num.find(qn('w:abstractNumId')).get(qn('w:val'))

    def new_list(self):
        ids = [int(n.get(qn('w:numId'))) for n in self.numbering.findall(qn('w:num'))]
        new_id = max(ids) + 1
        num = OxmlElement('w:num')
        num.set(qn('w:numId'), str(new_id))
        abs_el = OxmlElement('w:abstractNumId')
        abs_el.set(qn('w:val'), self.abstract_id)
        num.append(abs_el)
        override = OxmlElement('w:lvlOverride')
        override.set(qn('w:ilvl'), '0')
        start = OxmlElement('w:startOverride')
        start.set(qn('w:val'), '1')
        override.append(start)
        num.append(override)
        self.numbering.append(num)
        return new_id

    @staticmethod
    def apply(par, num_id):
        ppr = par._p.get_or_add_pPr()
        numpr = OxmlElement('w:numPr')
        ilvl = OxmlElement('w:ilvl')
        ilvl.set(qn('w:val'), '0')
        nid = OxmlElement('w:numId')
        nid.set(qn('w:val'), str(num_id))
        numpr.append(ilvl)
        numpr.append(nid)
        ppr.append(numpr)


def setup(doc):
    sec = doc.sections[0]
    sec.page_width, sec.page_height = Inches(8.5), Inches(11)
    for side in ('left_margin', 'right_margin', 'top_margin', 'bottom_margin'):
        setattr(sec, side, Inches(1))
    normal = doc.styles['Normal']
    normal.font.name = FONT
    normal.font.size = Pt(11)
    normal.element.rPr.rFonts.set(qn('w:eastAsia'), FONT)
    normal.paragraph_format.space_after = Pt(6)
    for name, size, color in (('Title', 24, '1F3864'), ('Heading 1', 16, '1F3864'),
                              ('Heading 2', 13, '2E5597')):
        st = doc.styles[name]
        st.font.name = FONT
        st.font.size = Pt(size)
        st.font.bold = True
        st.font.color.rgb = RGBColor.from_string(color)
        # Les styles de titre du modèle utilisent la police du thème : la retirer
        rfonts = st.element.rPr.rFonts
        for attr in ('w:asciiTheme', 'w:hAnsiTheme', 'w:eastAsiaTheme', 'w:cstheme'):
            if rfonts.get(qn(attr)) is not None:
                del rfonts.attrib[qn(attr)]
        pf = st.paragraph_format
        pf.keep_with_next = True
        pf.space_before = Pt(18 if name == 'Heading 1' else 12 if name == 'Heading 2' else 0)
        pf.space_after = Pt(6)
    # Numéro de page centré en pied de page (champ PAGE)
    fp = sec.footer.paragraphs[0]
    fp.alignment = WD_ALIGN_PARAGRAPH.CENTER
    run = fp.add_run()
    for kind, text in (('begin', None), (None, 'PAGE'), ('end', None)):
        if kind:
            fc = OxmlElement('w:fldChar')
            fc.set(qn('w:fldCharType'), kind)
            run._r.append(fc)
        else:
            instr = OxmlElement('w:instrText')
            instr.set(qn('xml:space'), 'preserve')
            instr.text = text
            run._r.append(instr)


def add_code(doc, lines, indent):
    for i, line in enumerate(lines):
        p = doc.add_paragraph()
        r = p.add_run(line if line else ' ')
        r.font.name = CODE_FONT
        r.font.size = Pt(10)
        pf = p.paragraph_format
        pf.left_indent = Inches(0.25 + 0.25 * indent)
        pf.space_after = Pt(0) if i < len(lines) - 1 else Pt(8)
        pf.space_before = Pt(0)
        shade(p._p.get_or_add_pPr(), 'F2F2F2')


def add_image(doc, path, alt, indent, base):
    full = os.path.join(base, path)
    img = DocxImage.from_file(full)
    dpi = img.horz_dpi or 96
    width_in = min(img.px_width / max(dpi, 96), MAX_WIDTH_IN - 0.25 * indent)
    p = doc.add_paragraph()
    p.alignment = WD_ALIGN_PARAGRAPH.CENTER
    p.paragraph_format.keep_with_next = False
    p.add_run().add_picture(full, width=Inches(width_in))
    # Texte de remplacement (accessibilité)
    docpr = p._p.find('.//' + qn('wp:docPr'))
    if docpr is not None:
        docpr.set('descr', alt)


def add_table(doc, rows):
    header, body = rows[0], rows[1:]
    table = doc.add_table(rows=len(rows), cols=len(header))
    table.style = 'Table Grid'
    widths = [Inches(2.0), Inches(4.5)] if len(header) == 2 else None
    for r, row in enumerate(rows):
        for c, cell_text in enumerate(row):
            cell = table.cell(r, c)
            if widths:
                cell.width = widths[c]
            p = cell.paragraphs[0]
            p.paragraph_format.space_after = Pt(2)
            add_inline(p, cell_text, bold=(r == 0))
            if r == 0:
                shade(cell._tc.get_or_add_tcPr(), 'D9E2F3')
    # Ne pas couper une ligne entre deux pages ; répéter l'en-tête
    for i, row in enumerate(table.rows):
        trpr = row._tr.get_or_add_trPr()
        cant = OxmlElement('w:cantSplit')
        trpr.append(cant)
        if i == 0:
            trpr.append(OxmlElement('w:tblHeader'))
    doc.add_paragraph().paragraph_format.space_after = Pt(0)


def convert(src, dst):
    base = os.path.dirname(os.path.abspath(src))
    lines = open(src, encoding='utf-8').read().splitlines()
    doc = Document()
    setup(doc)
    numbering = Numbering(doc)
    current_num = None
    i = 0
    while i < len(lines):
        line = lines[i]
        stripped = line.strip()
        indent = (len(line) - len(line.lstrip(' '))) // 3

        if not stripped:
            i += 1
            continue
        if stripped.startswith('```'):
            code = []
            i += 1
            while i < len(lines) and not lines[i].strip().startswith('```'):
                code.append(lines[i].strip())
                i += 1
            add_code(doc, code, indent)
            i += 1
            continue
        m = re.match(r'!\[([^\]]*)\]\(([^)]+)\)', stripped)
        if m:
            add_image(doc, m.group(2), m.group(1), indent, base)
            i += 1
            continue
        if stripped.startswith('|'):
            rows = []
            while i < len(lines) and lines[i].strip().startswith('|'):
                cells = [c.strip() for c in lines[i].strip().strip('|').split('|')]
                if not all(re.fullmatch(r':?-+:?', c) for c in cells):
                    rows.append(cells)
                i += 1
            add_table(doc, rows)
            current_num = None
            continue
        if stripped.startswith('# '):
            add_inline(doc.add_paragraph(style='Title'), stripped[2:])
        elif stripped.startswith('## '):
            add_inline(doc.add_paragraph(style='Heading 1'), stripped[3:])
            current_num = None
        elif stripped.startswith('### '):
            add_inline(doc.add_paragraph(style='Heading 2'), stripped[4:])
            current_num = None
        elif re.match(r'- ', stripped):
            style = 'List Bullet 2' if indent else 'List Bullet'
            add_inline(doc.add_paragraph(style=style), stripped[2:])
        elif re.match(r'\d+\. ', stripped):
            if current_num is None or stripped.startswith('1. '):
                current_num = numbering.new_list()
            p = doc.add_paragraph(style='List Number')
            Numbering.apply(p, current_num)
            add_inline(p, re.sub(r'^\d+\. ', '', stripped))
        else:
            p = doc.add_paragraph()
            if indent:
                p.paragraph_format.left_indent = Inches(0.25 * indent + 0.25)
            add_inline(p, stripped)
            if not indent:
                current_num = None
        i += 1

    doc.core_properties.title = lines[0].lstrip('# ').strip()
    doc.core_properties.author = 'banshee1971'
    doc.save(dst)


if __name__ == '__main__':
    convert(sys.argv[1], sys.argv[2])
    print('OK', sys.argv[2])
