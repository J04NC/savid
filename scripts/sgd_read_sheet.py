#!/usr/bin/env python3
"""Lee la primera hoja útil de un .xls/.xlsx y devuelve JSON por stdout."""
import json
import re
import sys
import zipfile
import xml.etree.ElementTree as ET
from collections import defaultdict


def col_letters(ref):
    m = re.match(r"([A-Z]+)", ref)
    return m.group(1) if m else ""


def xlsx_sheet_path(z, sheet_name=None):
    ns = {"m": "http://schemas.openxmlformats.org/spreadsheetml/2006/main"}
    rel_ns = "http://schemas.openxmlformats.org/officeDocument/2006/relationships"
    wb = ET.fromstring(z.read("xl/workbook.xml"))
    rels = ET.fromstring(z.read("xl/_rels/workbook.xml.rels"))
    rid_map = {r.get("Id"): r.get("Target") for r in rels}
    sheets = wb.findall("m:sheets/m:sheet", ns)
    if sheet_name:
        for sh in sheets:
            if sh.get("name", "").strip().lower() == sheet_name.strip().lower():
                target = rid_map.get(sh.get(f"{{{rel_ns}}}id"))
                if target:
                    return "xl/" + target.lstrip("/")
        raise ValueError(f"Hoja no encontrada: {sheet_name}")
    return "xl/" + rid_map.get(sheets[0].get(f"{{{rel_ns}}}id"), "worksheets/sheet1.xml").lstrip("/")


def read_xlsx(path, sheet_name=None):
    ns = {"m": "http://schemas.openxmlformats.org/spreadsheetml/2006/main"}
    with zipfile.ZipFile(path) as z:
        sst = ET.fromstring(z.read("xl/sharedStrings.xml"))
        strings = []
        for si in sst.findall("m:si", ns):
            t = si.find("m:t", ns)
            if t is not None:
                strings.append(t.text or "")
            else:
                strings.append("".join(x.text or "" for x in si.findall(".//m:t", ns)))
        sheet_path = xlsx_sheet_path(z, sheet_name)
        sheet = ET.fromstring(z.read(sheet_path))
        rows = defaultdict(dict)
        for c in sheet.findall(".//m:c", ns):
            ref = c.get("r", "")
            m = re.match(r"([A-Z]+)(\d+)", ref)
            if not m:
                continue
            col, row = m.group(1), int(m.group(2))
            is_el = c.find("m:is", ns)
            v = c.find("m:v", ns)
            if is_el is not None:
                t = is_el.find(".//m:t", ns)
                val = t.text if t is not None else ""
            elif v is not None:
                val = strings[int(v.text)] if c.get("t") == "s" else v.text
            else:
                val = ""
            rows[row][col] = val
    return {str(k): rows[k] for k in sorted(rows)}


def read_xls(path, sheet_names=None):
    import xlrd

    wb = xlrd.open_workbook(path)
    names = sheet_names or ["LIMPIO", "LMD 2026", "Hoja1", "Sheet1"]
    sh = None
    for n in names:
        if n in wb.sheet_names():
            sh = wb.sheet_by_name(n)
            break
    if sh is None:
        sh = wb.sheet_by_index(0)
    rows = {}
    for r in range(sh.nrows):
        row = {}
        for c in range(sh.ncols):
            letters = ""
            n = c
            while True:
                letters = chr(65 + (n % 26)) + letters
                n = n // 26 - 1
                if n < 0:
                    break
            val = sh.cell_value(r, c)
            if isinstance(val, float) and val == int(val):
                val = int(val)
            row[letters] = str(val).strip() if val != "" else ""
        rows[str(r + 1)] = row
    return rows


def main():
    if len(sys.argv) < 2:
        print(json.dumps({"error": "usage: sgd_read_sheet.py <file> [sheet_name]"}))
        sys.exit(1)
    path = sys.argv[1]
    sheet_name = sys.argv[2] if len(sys.argv) > 2 else None
    try:
        if path.lower().endswith(".xlsx"):
            data = read_xlsx(path, sheet_name)
        else:
            data = read_xls(path, [sheet_name] if sheet_name else None)
        print(json.dumps({"ok": True, "rows": data, "sheet": sheet_name}, ensure_ascii=False))
    except Exception as e:
        print(json.dumps({"ok": False, "error": str(e)}, ensure_ascii=False))
        sys.exit(2)


if __name__ == "__main__":
    main()
