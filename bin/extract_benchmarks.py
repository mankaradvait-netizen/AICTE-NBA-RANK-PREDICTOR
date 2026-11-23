import os
import sys
import glob
import re
import json

# Ensure user-level site-packages are always available in search path
user_site = os.path.expanduser(r"~\AppData\Roaming\Python\Python314\site-packages")
if os.path.exists(user_site) and user_site not in sys.path:
    sys.path.insert(0, user_site)

try:
    from pypdf import PdfReader
except ImportError:
    # Pure-Python fallback using built-in zlib for unassisted execution
    class SimplePage:
        def __init__(self, text):
            self._text = text
        def extract_text(self):
            return self._text

    class PdfReader:
        def __init__(self, path):
            import zlib
            self.pages = []
            if not os.path.exists(path):
                return
            with open(path, "rb") as f:
                content = f.read()
            found_chunks = []
            for stream in re.finditer(b"stream[\r\n]+(.*?)[\r\n]+endstream", content, re.DOTALL):
                try:
                    dec = zlib.decompress(stream.group(1)).decode("latin1", errors="ignore")
                    strings = re.findall(r"\((.*?)\)\s*Tj", dec)
                    if strings:
                        found_chunks.append(" ".join(strings))
                except Exception:
                    pass
            self.pages = [SimplePage("\n".join(found_chunks))]

# Locate repository root
SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
REPO_ROOT = os.path.abspath(os.path.join(SCRIPT_DIR, ".."))

reports = {
    2020: os.path.join(REPO_ROOT, "data", "IR2020_Report.pdf"),
    2021: os.path.join(REPO_ROOT, "data", "IR2021_Report.pdf"),
    2022: os.path.join(REPO_ROOT, "data", "IR2022_Report.pdf"),
    2023: os.path.join(REPO_ROOT, "data", "IR2023_Report.pdf"),
    2024: os.path.join(REPO_ROOT, "data", "IR2024_Report.pdf"),
    2025: os.path.join(REPO_ROOT, "data", "IR2025_Report.pdf"),
}

benchmarks = {}

for year, path in reports.items():
    if not os.path.exists(path):
        continue
    reader = PdfReader(path)
    print(f"Scanning {year}: {len(reader.pages)} pages")
    
    fru_match = None
    
    for idx, page in enumerate(reader.pages):
        text = page.extract_text() or ""
        
        # FRU per student in Engineering
        if "financial resource" in text.lower() or "fru" in text.lower():
            m = re.search(r"Engineering[^\d\n]*([\d,]+)", text, re.I)
            if m:
                fru_match = m.group(1).replace(",", "")
                
    benchmarks[year] = {
        "fru_per_student": fru_match,
    }

print("Benchmarks found:", json.dumps(benchmarks, indent=2))
