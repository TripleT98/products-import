"""Read-only verification of a specific control workbook; not a general-purpose importer."""
import argparse
import hashlib
import json
import posixpath
import re
from decimal import Decimal, ROUND_HALF_UP
from pathlib import Path
from zipfile import ZipFile
import xml.etree.ElementTree as ET


def inspect_sample(path):
    before = hashlib.sha256(path.read_bytes()).hexdigest()
    ns = {"m": "http://schemas.openxmlformats.org/spreadsheetml/2006/main"}
    with ZipFile(path, "r") as archive:
        sheets = ET.fromstring(archive.read("xl/workbook.xml")).find("m:sheets", ns)
        assert len(sheets) == 1
        rels = {r.get("Id"): r.get("Target") for r in ET.fromstring(archive.read("xl/_rels/workbook.xml.rels"))}
        sheet = sheets[0]
        target = rels[sheet.get("{http://schemas.openxmlformats.org/officeDocument/2006/relationships}id")]
        target = target.lstrip("/") if target.startswith("/") else posixpath.normpath("xl/" + target)
        strings = []
        if "xl/sharedStrings.xml" in archive.namelist():
            strings = ["".join(t.text or "" for t in item.findall(".//m:t", ns))
                       for item in ET.fromstring(archive.read("xl/sharedStrings.xml"))]
        root = ET.fromstring(archive.read(target))
        rows = []
        for row in root.findall("m:sheetData/m:row", ns):
            values = {}
            for cell in row.findall("m:c", ns):
                value = cell.find("m:v", ns)
                value = "" if value is None else value.text or ""
                if cell.get("t") == "s":
                    value = strings[int(value)]
                elif cell.get("t") == "inlineStr":
                    value = "".join(t.text or "" for t in cell.findall(".//m:t", ns))
                values[re.sub(r"\d+$", "", cell.get("r"))] = value
            if any(str(v).strip() for v in values.values()):
                rows.append((int(row.get("r")), values))
        headers = rows[0][1]
        data = [r for _, r in rows[1:]]
        mapping = {"externalCode": ["F", "Внешний код"], "name": ["E", "Наименование"],
                   "description": ["K", "Описание"], "price": ["I", "Цена: Цена продажи"],
                   "purchasePrice": ["L", "Закупочная цена"]}
        for column, header in mapping.values():
            assert headers[column] == header, (column, header)
        extra = [c for c, h in headers.items() if h.startswith("Доп. поле")]
        links = {c: [u.strip() for r in data for u in r.get(c, "").split(",") if u.strip()]
                 for c in ("AK", "AL")}
        urls = links["AK"] + links["AL"]
        def number(text):
            return Decimal(text.replace("\u00a0", "").replace(" ", "").replace(",", "."))
        discounts = sorted({str(((number(r["I"]) - number(r["L"])) / number(r["I"]) * 100)
                                .quantize(Decimal("0.01"), rounding=ROUND_HALF_UP)) for r in data})
        report = {
            "sourcePath": str(path), "bytes": path.stat().st_size, "sha256": before,
            "sheet": sheet.get("name"), "dimension": root.find("m:dimension", ns).get("ref"),
            "headerRow": rows[0][0], "dataRows": [n for n, _ in rows[1:]],
            "columnCount": len(headers), "productCount": len(data), "mapping": mapping,
            "uniqueExternalCodes": len({r["F"] for r in data}),
            "uniqueNames": len({r["E"] for r in data}), "extraColumnCount": len(extra),
            "attributeCount": sum(bool(r.get(c, "").strip()) for r in data for c in extra),
            "packagingLinks": len(links["AK"]), "uniquePackagingUrls": len(set(links["AK"])),
            "photoLinks": len(links["AL"]), "uniquePhotoUrls": len(set(links["AL"])),
            "imageCandidates": len(urls), "uniqueImageUrls": len(set(urls)),
            "discounts": discounts, "formulaCount": len(root.findall(".//m:f", ns)),
            "embeddedMediaCount": sum(n.startswith("xl/media/") for n in archive.namelist()),
            "externalLinkParts": sum(n.startswith("xl/externalLinks/") for n in archive.namelist()),
        }
    report["unchanged"] = before == hashlib.sha256(path.read_bytes()).hexdigest()
    assert report["unchanged"]
    for key, expected in {"sheet": "Лист1", "dimension": "A1:AX41", "headerRow": 1,
                          "columnCount": 50, "productCount": 40, "uniqueExternalCodes": 40,
                          "uniqueNames": 38, "extraColumnCount": 19, "attributeCount": 695,
                          "imageCandidates": 131, "uniqueImageUrls": 28,
                          "formulaCount": 0, "embeddedMediaCount": 0,
                          "discounts": ["33.33"]}.items():
        assert report[key] == expected, (key, report[key], expected)
    assert report["dataRows"] == list(range(2, 42))
    return report




if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--xlsx", type=Path, required=True,
                        help="Path to the control XLSX workbook (not included in the repository).")
    parser.add_argument("--report", type=Path)
    args = parser.parse_args()
    result = inspect_sample(args.xlsx)
    if args.report:
        args.report.write_text(json.dumps(result, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print("XLSX valid: 40 products / 695 attributes / 131 candidates / 28 URLs; discount 33.33; unchanged SHA-256")
