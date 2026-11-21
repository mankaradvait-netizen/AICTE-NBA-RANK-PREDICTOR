#!/usr/bin/env python3
"""
NIRF Engineering Document Intelligence: PDF Extraction Engine
Extracts page-by-page text, tables, numbers, and candidate facts from PDF documents.
"""

import sys
import json
import os
import re

def extract_pdf(pdf_path):
    if not os.path.exists(pdf_path):
        return {"error": f"File not found: {pdf_path}", "pages": []}

    try:
        from pypdf import PdfReader
    except ImportError:
        return {"error": "pypdf library is not installed", "pages": []}

    try:
        reader = PdfReader(pdf_path)
        total_pages = len(reader.pages)
        pages_data = []

        for idx, page in enumerate(reader.pages):
            page_num = idx + 1
            text = page.extract_text() or ""
            lines = [line.strip() for line in text.split("\n") if line.strip()]

            # Extract numeric key-value candidates from line text
            candidates = []
            # 1. Inline pattern: "Label: 123" or "Label - 123" or "Label 123"
            for line_idx, line in enumerate(lines):
                match = re.search(r'([A-Za-z\s\(\)/]+)[:\-=\t]\s*([₹$]?\s*[\d,]+(?:\.\d+)?)', line)
                if match:
                    label = match.group(1).strip()
                    val_str = match.group(2).replace('₹', '').replace('$', '').replace(',', '').strip()
                    try:
                        val = float(val_str) if '.' in val_str else int(val_str)
                        candidates.append({
                            "line_num": line_idx + 1,
                            "raw_label": label,
                            "raw_value": val,
                            "raw_text": line,
                            "locator": f"Page {page_num}, Line {line_idx + 1}"
                        })
                    except ValueError:
                        pass

            # 2. Sequential table pattern: Label on line i, number on line i+1
            for line_idx in range(len(lines) - 1):
                cur_line = lines[line_idx].strip()
                next_line = lines[line_idx + 1].strip()
                # Check if cur_line is text label and next_line is numeric
                clean_num = next_line.replace('₹', '').replace('$', '').replace(',', '').strip()
                if re.match(r'^[A-Za-z\s\(\)/_-]+$', cur_line) and re.match(r'^-?\d+(?:\.\d+)?$', clean_num):
                    if len(cur_line) >= 3 and cur_line.lower() not in ['indicator', 'value', 'period', 'note']:
                        try:
                            val = float(clean_num) if '.' in clean_num else int(clean_num)
                            candidates.append({
                                "line_num": line_idx + 1,
                                "raw_label": cur_line,
                                "raw_value": val,
                                "raw_text": f"{cur_line} -> {next_line}",
                                "locator": f"Page {page_num}, Line {line_idx + 1}"
                            })
                        except ValueError:
                            pass

            pages_data.append({
                "page": page_num,
                "text_length": len(text),
                "line_count": len(lines),
                "candidates": candidates,
                "raw_text": text[:1000] # preview snippet
            })

        return {
            "success": True,
            "filename": os.path.basename(pdf_path),
            "total_pages": total_pages,
            "pages": pages_data
        }
    except Exception as e:
        return {"error": f"Failed to parse PDF: {str(e)}", "pages": []}

if __name__ == "__main__":
    if len(sys.argv) < 2:
        print(json.dumps({"error": "Usage: python extract_pdf.py <pdf_path>"}))
        sys.exit(1)

    pdf_file = sys.argv[1]
    result = extract_pdf(pdf_file)
    print(json.dumps(result, indent=2))
