#!/usr/bin/env python3
"""
NIRF Engineering Document Intelligence: Advanced PDF Extraction Engine
Extracts page-by-page text, multi-year tables, calculated summary metrics,
and candidate facts from institutional PDF documents.
Prioritizes the latest academic/financial year for single-year baseline parameters.
"""

import sys
import json
import os
import re
import zlib

# Ensure user site packages are accessible across diverse Python environments
user_site = os.path.expanduser(r'~\AppData\Roaming\Python\Python314\site-packages')
if os.path.exists(user_site) and user_site not in sys.path:
    sys.path.insert(0, user_site)

def parse_year_weight(year_str):
    """Calculates a numeric chronological sort weight for academic/calendar years."""
    if not year_str:
        return 0.0
    # Academic / Financial year format: 2024-25, 2025-26, 2024/25
    m = re.search(r'20(\d{2})[-–/](\d{2,4})', str(year_str))
    if m:
        return 2000.0 + int(m.group(1)) + 0.5
    # Single calendar or ranking year: 2024, 2025, 2026, Ranking 2026
    m = re.search(r'20(\d{2})', str(year_str))
    if m:
        return 2000.0 + int(m.group(1))
    return 0.0

def clean_val(val_str, label=None):
    """Cleans numeric values, currency, LPA / Lakhs, Crores, percentages, and handles NA/null."""
    if val_str is None:
        return None
    s = str(val_str).strip()
    if not s or s.lower() in ['na', 'n/a', 'null', 'nil', '-', 'none', 'tbd', 'not applicable']:
        return None
        
    # Ignore remarks or phrases
    if re.search(r'result\s+not\s+yet\s+declare|in\s+words|amount\s+in\s+words', s, re.I):
        return None

    # LPA / Lakhs: e.g. "3.5 LPA", "₹2.6L", "2.6 L", "4.5 Lakhs", "4 LPA"
    m = re.search(r'^[₹$€£\s]*([0-9]+(?:\.[0-9]+)?)\s*(?:lpa|lakhs?|l)\b', s, re.I)
    if m:
        return float(m.group(1)) * 100000.0

    # Crores: e.g. "23.11 Cr", "₹ 23.11 Crores"
    m = re.search(r'^[₹$€£\s]*([0-9]+(?:\.[0-9]+)?)\s*(?:cr|crores?)\b', s, re.I)
    if m:
        return float(m.group(1)) * 10000000.0

    # Percent: e.g. "41%", "78 %", "45%"
    m = re.search(r'^[₹$€£\s]*([0-9]+(?:\.[0-9]+)?)\s*%', s)
    if m:
        return float(m.group(1))

    # Plain number with commas or currency symbols
    clean_num = re.sub(r'[₹$€£\s]', '', s).replace(',', '')
    try:
        val = float(clean_num) if '.' in clean_num else int(clean_num)
        # Contextual conversion if label mentions LPA or Lakhs
        if label and re.search(r'lpa|lakhs?', label, re.I) and 0.0 < val < 1000.0:
            return val * 100000.0
        return val
    except ValueError:
        return None

def extract_text_pypdf(pdf_path):
    """Extracts text per page using pypdf."""
    from pypdf import PdfReader
    reader = PdfReader(pdf_path)
    pages = []
    for idx, page in enumerate(reader.pages):
        txt = page.extract_text() or ""
        pages.append(txt)
    return pages

def extract_text_fallback(pdf_path):
    """Pure-Python fallback using zlib stream decompression."""
    pages = []
    with open(pdf_path, 'rb') as f:
        data = f.read()
    streams = re.findall(b'stream[\r\n]+(.*?)[\r\n]+endstream', data, re.DOTALL)
    current_page = []
    for s in streams:
        try:
            decomp = zlib.decompress(s)
            tjs = re.findall(rb'\((.*?)\)\s*Tj', decomp)
            if not tjs:
                tjs = re.findall(rb'\[(.*?)\]\s*TJ', decomp)
            page_text = b' '.join(tjs).decode('latin1', errors='ignore')
            clean_lines = [l.strip() for l in page_text.splitlines() if l.strip()]
            if clean_lines:
                current_page.extend(clean_lines)
        except Exception:
            pass
    if current_page:
        pages.append('\n'.join(current_page))
    return pages

def extract_pdf(pdf_path):
    if not os.path.exists(pdf_path):
        return {"error": f"File not found: {pdf_path}", "pages": []}

    pages_text = []
    try:
        pages_text = extract_text_pypdf(pdf_path)
    except Exception:
        try:
            pages_text = extract_text_fallback(pdf_path)
        except Exception as e:
            return {"error": f"Failed to extract PDF text: {str(e)}", "pages": []}

    total_pages = len(pages_text)
    pages_data = []

    for idx, text in enumerate(pages_text):
        page_num = idx + 1
        lines = [line.strip() for line in text.split("\n") if line.strip()]
        candidates = []
        
        # 1. Multi-Year Column Header & Multi-Year Table Detection
        # Finds header lines like "Financial Year 2024-25 2023-24 2022-23" or "2025-26 2024-25 2023-24"
        active_year_cols = []
        
        for line_idx, line in enumerate(lines):
            # Check for year header line
            year_matches = list(re.finditer(r'(?:FY|AY|Ranking\s*)?(20\d{2}[-–/]\d{2,4}|20\d{2})', line, re.I))
            if len(year_matches) >= 2:
                cols = []
                for ym in year_matches:
                    ystr = ym.group(1)
                    cols.append({
                        "year_str": ystr,
                        "weight": parse_year_weight(ystr),
                        "start": ym.start()
                    })
                active_year_cols = cols
                continue

            # If active year columns exist, check if line contains a label followed by values for each column
            if active_year_cols:
                m_row = re.search(r'^([A-Za-z\s\(\)/&%\.\-]+?)\s+([₹$€£\d,NAna\.\-]+(?:\s+[₹$€£\d,NAna\.\-]+)+)$', line)
                if m_row:
                    label = m_row.group(1).strip()
                    val_tokens = m_row.group(2).split()
                    
                    if len(label) >= 3 and not re.search(r'amount\s+in|utilized\s+amount|financial\s+year|ranking\s+\d{4}', label, re.I):
                        matched_entries = []
                        for col_i, token in enumerate(val_tokens[:len(active_year_cols)]):
                            col_info = active_year_cols[col_i]
                            val = clean_val(token, label)
                            if val is not None:
                                matched_entries.append({
                                    "year_str": col_info["year_str"],
                                    "weight": col_info["weight"],
                                    "val": val,
                                    "raw_token": token
                                })
                        
                        if matched_entries:
                            # Pick the LATEST academic/financial year with valid non-NA data
                            matched_entries.sort(key=lambda x: x["weight"], reverse=True)
                            latest = matched_entries[0]
                            
                            candidates.append({
                                "line_num": line_idx + 1,
                                "raw_label": label,
                                "raw_value": latest["val"],
                                "academic_year": latest["year_str"],
                                "is_latest": True,
                                "raw_text": line,
                                "locator": f"Page {page_num}, Line {line_idx + 1} ({latest['year_str']})",
                                "multi_year_data": {e["year_str"]: e["val"] for e in matched_entries}
                            })

            # 2. Multi-Year Row-Based Table Detection (e.g. Student List with Placement Data)
            m_ay_row = re.search(r'^(20\d{2}[-–/]\d{2,4})\s+(.+)$', line)
            if m_ay_row:
                ay_str = m_ay_row.group(1)
                tokens = m_ay_row.group(2).split()
                prev_lines_text = " ".join(lines[max(0, line_idx - 5):line_idx]).lower()
                is_placement_table = any(k in prev_lines_text for k in ['placement', 'matched', 'package', 'total students', 'match %'])
                
                if is_placement_table and len(tokens) >= 3:
                    cleaned_tokens = [clean_val(t) for t in tokens]
                    has_data = any(ct is not None for ct in cleaned_tokens)
                    
                    if has_data:
                        total_stu = cleaned_tokens[0] if len(cleaned_tokens) > 0 else None
                        placed = cleaned_tokens[1] if len(cleaned_tokens) > 1 else None
                        pct = cleaned_tokens[3] if len(cleaned_tokens) > 3 else (cleaned_tokens[2] if len(cleaned_tokens) > 2 else None)
                        sal_raw = " ".join(tokens[4:]) if len(tokens) >= 5 else (tokens[-1] if len(tokens) >= 3 else "")
                        sal = clean_val(sal_raw, 'Avg Package (LPA)')

                        if total_stu and total_stu > 0:
                            candidates.append({
                                "line_num": line_idx + 1,
                                "raw_label": "Graduating Cohort Total",
                                "raw_value": total_stu,
                                "academic_year": ay_str,
                                "is_latest": False,
                                "raw_text": line,
                                "locator": f"Page {page_num}, Line {line_idx + 1}"
                            })
                        if placed and placed > 0:
                            candidates.append({
                                "line_num": line_idx + 1,
                                "raw_label": "Placed Students Count",
                                "raw_value": placed,
                                "academic_year": ay_str,
                                "is_latest": False,
                                "raw_text": line,
                                "locator": f"Page {page_num}, Line {line_idx + 1}"
                            })
                        if pct and pct > 0:
                            norm_pct = pct if pct > 1.0 else pct * 100.0
                            candidates.append({
                                "line_num": line_idx + 1,
                                "raw_label": "Placement Match Percentage",
                                "raw_value": norm_pct,
                                "academic_year": ay_str,
                                "is_latest": False,
                                "raw_text": line,
                                "locator": f"Page {page_num}, Line {line_idx + 1}"
                            })
                        if sal and sal > 0:
                            candidates.append({
                                "line_num": line_idx + 1,
                                "raw_label": "Average Package (LPA)",
                                "raw_value": sal,
                                "academic_year": ay_str,
                                "is_latest": False,
                                "raw_text": line,
                                "locator": f"Page {page_num}, Line {line_idx + 1}"
                            })

            # 3. Key-Value & Calculated Data Detection (e.g. "number of ug students = 65" or "Total Expense: 455608138")
            m_kv = re.search(r'^([A-Za-z0-9_\s\(\)/&%\.\-]+?)\s*(?:[:\-=\t]|\bis\b)\s*([₹$€£\s]*[\d,]+(?:\.\d+)?(?:\s*(?:lpa|lakhs?|l|cr|crores?|%))?)$', line, re.I)
            if m_kv:
                label = m_kv.group(1).strip()
                val_raw = m_kv.group(2).strip()
                
                if not re.match(r'^\d{4}\s*[-–/]\s*\d{2,4}$', line) and not label.isdigit():
                    val = clean_val(val_raw, label)
                    if val is not None:
                        candidates.append({
                            "line_num": line_idx + 1,
                            "raw_label": label,
                            "raw_value": val,
                            "raw_text": line,
                            "is_latest": True,
                            "locator": f"Page {page_num}, Line {line_idx + 1}"
                        })

            # 4. Sequential Table Pattern: Label on line i, number on line i+1
            if line_idx < len(lines) - 1:
                cur_line = lines[line_idx].strip()
                next_line = lines[line_idx + 1].strip()
                if re.match(r'^[A-Za-z\s\(\)/_-]+$', cur_line) and len(cur_line) >= 4:
                    if cur_line.lower() not in ['indicator', 'value', 'period', 'note', 'status', 'rank']:
                        val = clean_val(next_line, cur_line)
                        if val is not None and is_numeric_scalar(val):
                            candidates.append({
                                "line_num": line_idx + 1,
                                "raw_label": cur_line,
                                "raw_value": val,
                                "raw_text": f"{cur_line} -> {next_line}",
                                "is_latest": True,
                                "locator": f"Page {page_num}, Line {line_idx + 1}"
                            })

        # Post-process row-based AY tables to mark the LATEST valid academic year
        ay_entries_by_label = {}
        for c in candidates:
            if c.get("academic_year"):
                lbl = c["raw_label"]
                ay_entries_by_label.setdefault(lbl, []).append(c)
                
        for lbl, entry_list in ay_entries_by_label.items():
            entry_list.sort(key=lambda x: parse_year_weight(x.get("academic_year")), reverse=True)
            if entry_list:
                entry_list[0]["is_latest"] = True

        pages_data.append({
            "page": page_num,
            "text_length": len(text),
            "line_count": len(lines),
            "candidates": candidates,
            "raw_text": text[:1000]
        })

    return {
        "success": True,
        "filename": os.path.basename(pdf_path),
        "total_pages": total_pages,
        "pages": pages_data
    }

def is_numeric_scalar(v):
    return isinstance(v, (int, float))

if __name__ == "__main__":
    if len(sys.argv) < 2:
        print(json.dumps({"error": "Usage: python extract_pdf.py <pdf_path>"}))
        sys.exit(1)

    pdf_file = sys.argv[1]
    result = extract_pdf(pdf_file)
    print(json.dumps(result, indent=2))
