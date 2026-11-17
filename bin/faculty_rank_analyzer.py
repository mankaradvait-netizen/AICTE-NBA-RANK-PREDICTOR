#!/usr/bin/env python3
"""
AICTE / NBA / NIRF Faculty Data Processor, Sorting & Rank Prediction Engine
==========================================================================
Deterministic calculation, sorting, and institutional accreditation analysis
using individual faculty records.
"""

import sys
import os
import csv
import math
import json
import argparse
from datetime import datetime
from typing import List, Dict, Any, Tuple

# Reference benchmarks for NIRF Engineering
BENCHMARKS = {
    'PU_MAX': 5.0,         # Publications per faculty
    'CC_MAX': 25.0,        # Citations per publication
    'TOP25_MAX': 2.0,      # Top 25% percentile papers per faculty
    'RF_MAX': 2000000.0,   # Rs 20 Lakhs per faculty research earnings
    'CF_MAX': 500000.0,    # Rs 5 Lakhs per faculty consultancy earnings
    'NP_MAX': 1000.0,
    'BC_MAX': 150000.0,
    'BO_MAX': 300000.0,
    'PG_MAX': 50.0,
    'PP_MAX': 100.0,
    'GMS_MAX': 1800000.0,
    'NPHD_MAX': 250.0,
    'RD_STATE_MAX': 50.0,
    'RD_INT_MAX': 10.0,
    'ESC_MAX': 40.0,
    'PR_MAX': 100.0,
}

def parse_date(date_str: str) -> datetime:
    """Robust date parser supporting multiple standard formats."""
    clean = str(date_str).strip()
    for fmt in ('%Y-%m-%d', '%d/%m/%Y', '%d-%m-%Y', '%Y/%m/%d', '%d.%m.%Y'):
        try:
            return datetime.strptime(clean, fmt)
        except ValueError:
            continue
    # Fallback to current date or default
    return datetime(2020, 1, 1)

def parse_number(val: Any, default: float = 0.0) -> float:
    """Safely parse numbers stripping whitespace, currency signs, commas."""
    if val is None:
        return default
    s = str(val).strip().replace(',', '').replace('₹', '').replace('$', '').replace('INR', '').strip()
    try:
        return float(s)
    except ValueError:
        return default

def is_phd(education_level: str) -> bool:
    """Check if qualification matches PhD."""
    lvl = str(education_level).strip().lower()
    return any(p in lvl for p in ['ph.d', 'phd', 'doctor', 'd.phil'])

def is_full_time(faculty_type: str) -> bool:
    """Check if faculty appointment is full-time regular."""
    t = str(faculty_type).strip().lower()
    if 'full time' in t or 'full-time' in t or 'regular' in t or 'permanent' in t:
        return True
    return False

def calculate_experience_years(start_date: datetime, ref_date: datetime = None) -> float:
    """Calculate experience in years relative to reference date (defaults to today)."""
    if ref_date is None:
        ref_date = datetime.now()
    days = (ref_date - start_date).days
    return max(0.0, round(days / 365.25, 2))

class FacultyMember:
    def __init__(self, raw: Dict[str, str], ref_date: datetime = None):
        self.raw = raw
        self.name = raw.get('Faculty Name', '').strip()
        self.faculty_id = raw.get('Faculty ID', '').strip()
        self.gender = raw.get('Gender', '').strip().capitalize()
        self.dob = parse_date(raw.get('DOB', '1980-01-01'))
        self.start_date = parse_date(raw.get('Strt of teaching Date ', raw.get('Strt of teaching Date', '2015-01-01')))
        self.salary = parse_number(raw.get('Sallery (yearly)', 0))
        self.designation = raw.get('Designation ', raw.get('Designation', '')).strip()
        self.pwd = 'yes' in str(raw.get('PWD', '')).strip().lower()
        self.pub_passed = int(parse_number(raw.get('Publication passed', 0)))
        self.pub_failed = int(parse_number(raw.get('Publications Failed', 0)))
        self.earnings_pub = parse_number(raw.get('Earnings from Publications', 0))
        self.earnings_counselling = parse_number(raw.get('Earnings from counselling', 0))
        self.citations_passed = int(parse_number(raw.get('Citations passed', 0)))
        self.citations_failed = int(parse_number(raw.get('Citations Failed', 0)))
        self.education = raw.get('Education Level', '').strip()
        self.has_phd = is_phd(self.education)
        self.faculty_type = raw.get('Type Of Faculty (Full time, Contract FOR 2 SEM, Visiting and etc)', '').strip()
        self.is_fulltime = is_full_time(self.faculty_type)
        self.country = raw.get('Country ', raw.get('Country', '')).strip()
        self.state = raw.get('State', '').strip()

        # Calculated fields
        self.experience_years = calculate_experience_years(self.start_date, ref_date)
        self.total_pubs = self.pub_passed + self.pub_failed
        self.pub_acceptance_rate = (self.pub_passed / self.total_pubs * 100.0) if self.total_pubs > 0 else 0.0
        self.total_citations = self.citations_passed + self.citations_failed
        self.citations_per_pub = (self.citations_passed / self.pub_passed) if self.pub_passed > 0 else 0.0
        self.total_earnings = self.earnings_pub + self.earnings_counselling

        # Experience bands (NIRF F1 <= 8, F2 8-15, F3 > 15)
        if self.experience_years <= 8.0:
            self.exp_band = 'F1'
        elif self.experience_years <= 15.0:
            self.exp_band = 'F2'
        else:
            self.exp_band = 'F3'

        # Composite individual performance score
        # 10 pts per passed publication, 2 pts per passed citation, 1 pt per 50,000 INR generated,
        # 25 pts for PhD qualification, 2 pts per experience year
        self.performance_score = round(
            (10.0 * self.pub_passed) +
            (2.0 * self.citations_passed) +
            (self.total_earnings / 50000.0) +
            (25.0 if self.has_phd else 0.0) +
            (2.0 * min(30.0, self.experience_years)),
            2
        )

    def to_dict(self) -> Dict[str, Any]:
        return {
            'Faculty Name': self.name,
            'Faculty ID': self.faculty_id,
            'Designation': self.designation,
            'Gender': self.gender,
            'Experience Years': self.experience_years,
            'Exp Band': self.exp_band,
            'Has PhD': 'Yes' if self.has_phd else 'No',
            'Full Time': 'Yes' if self.is_fulltime else 'No',
            'Publications Passed': self.pub_passed,
            'Publications Failed': self.pub_failed,
            'Citations Passed': self.citations_passed,
            'Citations Per Pub': round(self.citations_per_pub, 2),
            'Pub Earnings (INR)': self.earnings_pub,
            'Consultancy Earnings (INR)': self.earnings_counselling,
            'Total Earnings (INR)': self.total_earnings,
            'Annual Salary (INR)': self.salary,
            'State': self.state,
            'Country': self.country,
            'Performance Score': self.performance_score,
        }

class FacultyDatasetAnalyzer:
    def __init__(self, faculty_list: List[FacultyMember]):
        self.faculty_list = faculty_list
        self.full_time_faculty = [f for f in faculty_list if f.is_fulltime]

    def sort_by(self, criterion: str = 'score', ascending: bool = False) -> List[FacultyMember]:
        """Sort faculty members by various metrics."""
        key_map = {
            'score': lambda f: f.performance_score,
            'pubs': lambda f: f.pub_passed,
            'citations': lambda f: f.citations_passed,
            'earnings': lambda f: f.total_earnings,
            'consultancy': lambda f: f.earnings_counselling,
            'experience': lambda f: f.experience_years,
            'salary': lambda f: f.salary,
            'name': lambda f: f.name.lower(),
        }
        sorter = key_map.get(criterion, key_map['score'])
        return sorted(self.faculty_list, key=sorter, reverse=not ascending)

    def compute_aggregates(self, home_state: str = 'Maharashtra', home_country: str = 'India') -> Dict[str, Any]:
        """Aggregate faculty metrics into AICTE / NBA / NIRF inputs."""
        ft = self.full_time_faculty
        F = len(ft)
        if F == 0:
            F = len(self.faculty_list) # fallback
            ft = self.faculty_list

        F_PHD = sum(1 for f in ft if f.has_phd)
        F1 = sum(1 for f in ft if f.exp_band == 'F1')
        F2 = sum(1 for f in ft if f.exp_band == 'F2')
        F3 = sum(1 for f in ft if f.exp_band == 'F3')

        # Total research & consultancy
        total_pubs = sum(f.pub_passed for f in ft)
        total_citations = sum(f.citations_passed for f in ft)
        total_pub_earnings = sum(f.earnings_pub for f in ft)
        total_consultancy = sum(f.earnings_counselling for f in ft)

        # Per faculty averages for NIRF
        RF = total_pub_earnings / F if F > 0 else 0.0
        CF = total_consultancy / F if F > 0 else 0.0

        # Gender diversity
        female_count = sum(1 for f in ft if f.gender == 'Female')
        FW_pct = (female_count / F * 100.0) if F > 0 else 0.0

        # Regional diversity (faculty)
        other_state_count = sum(1 for f in ft if f.state.strip().lower() != home_state.lower() and f.country.strip().lower() == home_country.lower())
        international_count = sum(1 for f in ft if f.country.strip().lower() != home_country.lower())

        # Cadre Distribution (Professor : Associate Professor : Assistant Professor)
        profs = sum(1 for f in ft if 'professor' in f.designation.lower() and 'associate' not in f.designation.lower() and 'assistant' not in f.designation.lower())
        assoc_profs = sum(1 for f in ft if 'associate' in f.designation.lower())
        asst_profs = sum(1 for f in ft if 'assistant' in f.designation.lower())
        other_cadre = F - (profs + assoc_profs + asst_profs)

        # Average salaries
        avg_salary = sum(f.salary for f in ft) / F if F > 0 else 0.0

        return {
            'total_registered_faculty': len(self.faculty_list),
            'F': F,  # Regular full-time
            'F_PHD': F_PHD,
            'FRA': round((F_PHD / F * 100.0) if F > 0 else 0.0, 2), # % PhD
            'F1': F1,
            'F2': F2,
            'F3': F3,
            'P': total_pubs,
            'CC': total_citations,
            'TOP25': round(total_citations * 0.25), # approximate top 25% percentile citations
            'total_pub_earnings': total_pub_earnings,
            'total_consultancy': total_consultancy,
            'RF': round(RF, 2), # Average annual research funding per faculty
            'CF': round(CF, 2), # Average annual consultancy per faculty
            'FW_pct': round(FW_pct, 2),
            'female_count': female_count,
            'other_state_count': other_state_count,
            'international_count': international_count,
            'profs': profs,
            'assoc_profs': assoc_profs,
            'asst_profs': asst_profs,
            'other_cadre': other_cadre,
            'avg_salary': round(avg_salary, 2),
        }

    def evaluate_ranking_and_accreditation(self, institutional_context: Dict[str, float] = None) -> Dict[str, Any]:
        """
        Evaluate NIRF & NBA formula scores deterministically.
        Combines aggregated faculty inputs with standard student & institutional baselines.
        """
        aggr = self.compute_aggregates()

        # Merge standard institutional baseline values if not provided
        ctx = {
            'NT': 1500,     # Sanctioned student intake
            'NE': 1400,     # Enrolled students
            'NP': 100,      # PhD students enrolled
            'BC': 45000,    # Capital expenditure per student
            'BO': 120000,   # Operational expenditure per student
            'Pret': 0,      # Retracted publications
            'Cret': 0,      # Retracted citations
            'PG': 8,        # Patents granted
            'PP': 20,       # Patents published
            'NP_pct': 82.5, # Placement %
            'NHS_pct': 10.0,# Higher studies %
            'NG_pct': 88.0, # Exam pass %
            'GMS_val': 750000, # Median salary
            'NPHD': 25,     # PhD graduated
            'N_state_pct': 25.0,
            'N_country_pct': 3.0,
            'SW_pct': 32.0, # Female student %
            'ESC_pct': 25.0,# Economically challenged %
            'PCS_score': 18.0, # Physically challenged facilities
            'PR_survey_val': 55.0, # Peer perception survey
        }
        if institutional_context:
            ctx.update(institutional_context)

        # Faculty variables from aggregated dataset
        F = aggr['F']
        F_PHD = aggr['F_PHD']
        F1 = aggr['F1']
        F2 = aggr['F2']
        F3 = aggr['F3']
        P = aggr['P']
        CC = aggr['CC']
        TOP25 = aggr['TOP25']
        RF = aggr['RF']
        CF = aggr['CF']
        FW_pct = aggr['FW_pct']

        NT = ctx['NT']
        NE = ctx['NE']
        NP = ctx['NP']
        BC = ctx['BC']
        BO = ctx['BO']

        # 1. TLR Calculations (Weight = 0.30)
        # SS: Student Strength (Max 20)
        ss = 15.0 * min(1.0, NE / NT) + 5.0 * min(1.0, NP / BENCHMARKS['NP_MAX'])
        # FSR: Faculty-Student Ratio (Max 30, target 1:15 = 0.0667)
        fsr_val = F / NT if NT > 0 else 0
        fsr_score = 30.0 * min(1.0, fsr_val / 0.0667)
        # FQE: Faculty Quality and Experience (Max 20)
        fra = (F_PHD / F * 100.0) if F > 0 else 0
        fq = 10.0 * min(1.0, fra / 95.0)
        fe = (3.0 * min(1.0, (F1 / F) / 0.33) +
              3.0 * min(1.0, (F2 / F) / 0.33) +
              4.0 * min(1.0, (F3 / F) / 0.34)) if F > 0 else 0
        fqe_score = min(20.0, fq + fe)
        # FRU: Financial Resources (Max 30)
        fru_score = 7.5 * min(1.0, BC / BENCHMARKS['BC_MAX']) + 22.5 * min(1.0, BO / BENCHMARKS['BO_MAX'])
        tlr_raw = ss + fsr_score + fqe_score + fru_score
        tlr_weighted = tlr_raw * 0.30

        # 2. RPC Calculations (Weight = 0.30)
        frq = max(F, NT / 15.0)
        # PU: Publications Metric (Max 35)
        pu_ratio = (P / frq) / BENCHMARKS['PU_MAX'] if frq > 0 else 0
        pu_score = max(0.0, 35.0 * min(1.0, pu_ratio) - 5.0 * min(1.0, ctx['Pret']))
        # QP: Quality of Publications (Max 40)
        cc_per_p = (CC / P) if P > 0 else 0
        top25_ratio = (TOP25 / frq) / BENCHMARKS['TOP25_MAX'] if frq > 0 else 0
        qp_score = max(0.0, 20.0 * min(1.0, cc_per_p / BENCHMARKS['CC_MAX']) + 20.0 * min(1.0, top25_ratio) - 5.0 * min(1.0, ctx['Cret']))
        # IPR: Patents (Max 15)
        ipr_score = 10.0 * min(1.0, ctx['PG'] / BENCHMARKS['PG_MAX']) + 5.0 * min(1.0, ctx['PP'] / BENCHMARKS['PP_MAX'])
        # FPPP: Footprint of Projects (Max 10)
        fppp_score = 7.5 * min(1.0, RF / BENCHMARKS['RF_MAX']) + 2.5 * min(1.0, CF / BENCHMARKS['CF_MAX'])
        rpc_raw = pu_score + qp_score + ipr_score + fppp_score
        rpc_weighted = rpc_raw * 0.30

        # 3. GO Calculations (Weight = 0.20)
        gph_score = 40.0 * min(1.0, (ctx['NP_pct'] + ctx['NHS_pct']) / 100.0)
        gue_score = 15.0 * min(1.0, ctx['NG_pct'] / 80.0)
        gms_score = 25.0 * min(1.0, ctx['GMS_val'] / BENCHMARKS['GMS_MAX'])
        gphd_score = 20.0 * min(1.0, ctx['NPHD'] / BENCHMARKS['NPHD_MAX'])
        go_raw = gph_score + gue_score + gms_score + gphd_score
        go_weighted = go_raw * 0.20

        # 4. OI Calculations (Weight = 0.10)
        rd_score = 25.0 * min(1.0, ctx['N_state_pct'] / BENCHMARKS['RD_STATE_MAX']) + 5.0 * min(1.0, ctx['N_country_pct'] / BENCHMARKS['RD_INT_MAX'])
        wd_score = 15.0 * min(1.0, ctx['SW_pct'] / 50.0) + 15.0 * min(1.0, FW_pct / 20.0)
        escs_score = 20.0 * min(1.0, ctx['ESC_pct'] / BENCHMARKS['ESC_MAX'])
        pcs_score = min(20.0, ctx['PCS_score'])
        oi_raw = rd_score + wd_score + escs_score + pcs_score
        oi_weighted = oi_raw * 0.10

        # 5. PR Calculations (Weight = 0.10)
        pr_raw = 100.0 * min(1.0, ctx['PR_survey_val'] / BENCHMARKS['PR_MAX'])
        pr_weighted = pr_raw * 0.10

        total_score = round(tlr_weighted + rpc_weighted + go_weighted + oi_weighted + pr_weighted, 2)

        # Predicted Rank Band
        if total_score >= 60.0:
            rank_band = "TOP_50"
            rank_desc = "Top 50 National Institutions (Tier 1 Elite)"
        elif total_score >= 48.0:
            rank_band = "TOP_100"
            rank_desc = "Top 100 National Institutions (Tier 2)"
        elif total_score >= 38.0:
            rank_band = "TOP_300"
            rank_desc = "Top 300 Institutions (Tier 3 Accredited)"
        else:
            rank_band = "UNRANKED"
            rank_desc = "Below Top 300 (Needs Remediation)"

        # NBA Criterion 5 Check (Faculty Cadre & Ratio)
        # Standard: Cadre ratio 1:2:6 (Prof:Assoc:Asst)
        total_cadre = aggr['profs'] + aggr['assoc_profs'] + aggr['asst_profs']
        cadre_compliant = False
        if total_cadre > 0:
            p_ratio = aggr['profs'] / total_cadre
            assoc_ratio = aggr['assoc_profs'] / total_cadre
            asst_ratio = aggr['asst_profs'] / total_cadre
            # Ideal is ~ 11% Prof, 22% Assoc, 67% Asst
            cadre_compliant = (p_ratio >= 0.08 and assoc_ratio >= 0.15)

        sfr_actual = round(NT / F, 1) if F > 0 else 999.0
        nba_sfr_status = "Compliant (<= 1:20 for NBA, <= 1:15 for NIRF full marks)" if sfr_actual <= 20 else "Deficient (> 1:20)"

        # Gap analysis and actionable recommendations
        recommendations = []
        if sfr_actual > 15.0:
            needed_faculty = math.ceil(NT / 15.0) - F
            recommendations.append(f"Faculty-Student Ratio is 1:{sfr_actual}. Recruit {needed_faculty} additional full-time faculty to attain 1:15 standard.")
        if fra < 60.0:
            recommendations.append(f"PhD faculty percentage is {round(fra, 1)}% (below recommended 60%). Increase doctoral hiring or sponsor faculty PhD programs.")
        if (aggr['profs'] == 0 or aggr['assoc_profs'] == 0):
            recommendations.append("Cadre imbalance detected: Need balanced 1:2:6 ratio of Professor : Associate Professor : Assistant Professor.")
        if RF < 200000:
            recommendations.append(f"Research funding per faculty is ₹{int(RF):,} (target: ₹5,00,000+). Encourage national funding agency submissions (DST, SERB, AICTE RPS).")
        if CF < 50000:
            recommendations.append(f"Consultancy earnings per faculty is ₹{int(CF):,} (target: ₹1,00,000+). Establish formal industry consulting MOUs.")
        if FW_pct < 20.0:
            recommendations.append(f"Female faculty representation is {FW_pct}% (target: >= 20% for max NIRF WD score).")

        return {
            'total_score': total_score,
            'predicted_rank_band': rank_band,
            'predicted_rank_desc': rank_desc,
            'main_parameters': {
                'TLR': {'raw': round(tlr_raw, 2), 'weight': 0.30, 'weighted': round(tlr_weighted, 2), 'sub': {'SS': round(ss, 2), 'FSR': round(fsr_score, 2), 'FQE': round(fqe_score, 2), 'FRU': round(fru_score, 2)}},
                'RPC': {'raw': round(rpc_raw, 2), 'weight': 0.30, 'weighted': round(rpc_weighted, 2), 'sub': {'PU': round(pu_score, 2), 'QP': round(qp_score, 2), 'IPR': round(ipr_score, 2), 'FPPP': round(fppp_score, 2)}},
                'GO':  {'raw': round(go_raw, 2), 'weight': 0.20, 'weighted': round(go_weighted, 2), 'sub': {'GPH': round(gph_score, 2), 'GUE': round(gue_score, 2), 'GMS': round(gms_score, 2), 'GPHD': round(gphd_score, 2)}},
                'OI':  {'raw': round(oi_raw, 2), 'weight': 0.10, 'weighted': round(oi_weighted, 2), 'sub': {'RD': round(rd_score, 2), 'WD': round(wd_score, 2), 'ESCS': round(escs_score, 2), 'PCS': round(pcs_score, 2)}},
                'PR':  {'raw': round(pr_raw, 2), 'weight': 0.10, 'weighted': round(pr_weighted, 2), 'sub': {'PR_survey': round(pr_raw, 2)}},
            },
            'nba_criterion_5': {
                'sfr_actual': f"1:{sfr_actual}",
                'sfr_status': nba_sfr_status,
                'cadre_ratio': f"{aggr['profs']} Prof : {aggr['assoc_profs']} Assoc : {aggr['asst_profs']} Asst",
                'cadre_compliant': cadre_compliant,
                'phd_percentage': f"{round(fra, 1)}%",
                'faculty_retention_experience': f"F1 (<=8y): {F1}, F2 (8-15y): {F2}, F3 (>15y): {F3}",
            },
            'recommendations': recommendations,
            'aggregates': aggr,
        }

def load_faculty_csv(file_path: str) -> List[FacultyMember]:
    """Parse CSV file matching the faculty schema."""
    if not os.path.exists(file_path):
        raise FileNotFoundError(f"File not found: {file_path}")

    members = []
    with open(file_path, mode='r', encoding='utf-8-sig', errors='replace') as f:
        reader = csv.DictReader(f)
        for row in reader:
            # Clean keys
            cleaned_row = {k.strip(): v for k, v in row.items() if k is not None}
            if any(cleaned_row.values()):
                members.append(FacultyMember(cleaned_row))
    return members

def export_ranked_csv(members: List[FacultyMember], output_path: str):
    """Export sorted leaderboard to CSV."""
    if not members:
        return
    keys = list(members[0].to_dict().keys())
    with open(output_path, mode='w', newline='', encoding='utf-8') as f:
        writer = csv.DictWriter(f, fieldnames=keys)
        writer.writeheader()
        for m in members:
            writer.writerow(m.to_dict())

def main():
    parser = argparse.ArgumentParser(description="AICTE / NBA / NIRF Faculty Data Processor and Rank Analyzer")
    parser.add_argument('--file', '-f', type=str, default='data/sample_faculty_data.csv', help="Path to Faculty CSV file")
    parser.add_argument('--sort', '-s', type=str, default='score', choices=['score', 'pubs', 'citations', 'earnings', 'experience', 'salary', 'name'], help="Sort faculty by metric")
    parser.add_argument('--order', '-o', type=str, default='desc', choices=['asc', 'desc'], help="Sort order")
    parser.add_argument('--export', '-e', type=str, default='data/faculty_ranked_leaderboard.csv', help="Export sorted leaderboard to CSV")
    parser.add_argument('--json', '-j', action='store_true', help="Output results in JSON format")

    args = parser.parse_args()

    try:
        members = load_faculty_csv(args.file)
    except Exception as e:
        print(f"Error loading file {args.file}: {e}", file=sys.stderr)
        sys.exit(1)

    if not members:
        print("No valid faculty records found in CSV.", file=sys.stderr)
        sys.exit(1)

    analyzer = FacultyDatasetAnalyzer(members)
    sorted_members = analyzer.sort_by(criterion=args.sort, ascending=(args.order == 'asc'))
    results = analyzer.evaluate_ranking_and_accreditation()

    if args.export:
        export_ranked_csv(sorted_members, args.export)

    if args.json:
        payload = {
            'sorted_faculty': [m.to_dict() for m in sorted_members],
            'evaluation': results
        }
        print(json.dumps(payload, indent=2))
        return

    # Rich formatted terminal display
    aggr = results['aggregates']
    print("\n" + "=" * 80)
    print(" AICTE / NBA / NIRF FACULTY DATA ANALYSIS & RANK PREDICTOR REPORT ")
    print("=" * 80)
    print(f"Analyzed File: {args.file}")
    print(f"Total Faculty Registered : {aggr['total_registered_faculty']}")
    print(f"Full-Time Regular Faculty: {aggr['F']} (PhD: {aggr['F_PHD']} | {aggr['FRA']}%)")
    print(f"Experience Distribution  : F1 (<=8 yrs): {aggr['F1']} | F2 (8-15 yrs): {aggr['F2']} | F3 (>15 yrs): {aggr['F3']}")
    print(f"Total Publications (P)   : {aggr['P']} passed")
    print(f"Total Citations (CC)     : {aggr['CC']} passed")
    print(f"Research Funding (RF)    : ₹{aggr['total_pub_earnings']:,} (₹{aggr['RF']:,} / faculty)")
    print(f"Consultancy Amount (CF)  : ₹{aggr['total_consultancy']:,} (₹{aggr['CF']:,} / faculty)")
    print(f"Female Faculty (FW%)     : {aggr['FW_pct']}% ({aggr['female_count']} female faculty)")
    print(f"Cadre Distribution       : {aggr['profs']} Prof, {aggr['assoc_profs']} Assoc Prof, {aggr['asst_profs']} Asst Prof")
    print("-" * 80)

    print("\n" + "-" * 80)
    print(f" TOP FACULTY LEADERBOARD (Sorted by {args.sort.upper()} {args.order.upper()}):")
    print("-" * 80)
    print(f"{'Rank':<5}{'Faculty Name':<24}{'Designation':<20}{'Exp(y)':<8}{'PhD':<5}{'Pubs':<6}{'Cites':<7}{'Earnings':<12}{'Score':<8}")
    print("-" * 95)
    for idx, f in enumerate(sorted_members[:15], 1):
        print(f"{idx:<5}{f.name:<24}{f.designation[:18]:<20}{f.experience_years:<8}{'Yes' if f.has_phd else 'No':<5}{f.pub_passed:<6}{f.citations_passed:<7}₹{int(f.total_earnings):<11,}{f.performance_score:<8}")

    print("\n" + "=" * 80)
    print(" NIRF DETERMINISTIC SCORING & PREDICTED ACCREDITATION STANDING ")
    print("=" * 80)
    print(f" PREDICTED TOTAL SCORE: {results['total_score']} / 100")
    print(f" PREDICTED RANK BAND  : {results['predicted_rank_band']} -> {results['predicted_rank_desc']}")
    print("-" * 80)
    print(f"  [TLR] Teaching, Learning & Resources : {results['main_parameters']['TLR']['weighted']:>5.2f} / 30.00  (Raw: {results['main_parameters']['TLR']['raw']:>5.2f}/100)")
    print(f"        • Student Strength (SS)        : {results['main_parameters']['TLR']['sub']['SS']:>5.2f} / 20.00")
    print(f"        • Faculty-Student Ratio (FSR)  : {results['main_parameters']['TLR']['sub']['FSR']:>5.2f} / 30.00")
    print(f"        • Faculty Quality & Exp (FQE)  : {results['main_parameters']['TLR']['sub']['FQE']:>5.2f} / 20.00")
    print(f"        • Financial Utilization (FRU)  : {results['main_parameters']['TLR']['sub']['FRU']:>5.2f} / 30.00")
    print(f"  [RPC] Research & Professional Pract. : {results['main_parameters']['RPC']['weighted']:>5.2f} / 30.00  (Raw: {results['main_parameters']['RPC']['raw']:>5.2f}/100)")
    print(f"        • Publications Metric (PU)     : {results['main_parameters']['RPC']['sub']['PU']:>5.2f} / 35.00")
    print(f"        • Quality of Publications (QP) : {results['main_parameters']['RPC']['sub']['QP']:>5.2f} / 40.00")
    print(f"        • Patents Granted/Pub (IPR)    : {results['main_parameters']['RPC']['sub']['IPR']:>5.2f} / 15.00")
    print(f"        • Projects & Consultancy (FPPP): {results['main_parameters']['RPC']['sub']['FPPP']:>5.2f} / 10.00")
    print(f"  [GO]  Graduation Outcomes            : {results['main_parameters']['GO']['weighted']:>5.2f} / 20.00  (Raw: {results['main_parameters']['GO']['raw']:>5.2f}/100)")
    print(f"  [OI]  Outreach and Inclusivity       : {results['main_parameters']['OI']['weighted']:>5.2f} / 10.00  (Raw: {results['main_parameters']['OI']['raw']:>5.2f}/100)")
    print(f"  [PR]  Peer Perception                : {results['main_parameters']['PR']['weighted']:>5.2f} / 10.00  (Raw: {results['main_parameters']['PR']['raw']:>5.2f}/100)")
    print("-" * 80)

    print("\n NBA CRITERION 5 (FACULTY INFORMATION & CONTRIBUTIONS):")
    print(f"  • Student-to-Faculty Ratio (SFR) : {results['nba_criterion_5']['sfr_actual']} ({results['nba_criterion_5']['sfr_status']})")
    print(f"  • Faculty Cadre Proportion       : {results['nba_criterion_5']['cadre_ratio']}")
    print(f"  • Faculty Cadre Compliance       : {'PASS (Meets AICTE 1:2:6 ratio requirements)' if results['nba_criterion_5']['cadre_compliant'] else 'ATTENTION NEEDED (Prof/Assoc Prof proportion low)'}")
    print(f"  • PhD Faculty Proportion        : {results['nba_criterion_5']['phd_percentage']}")

    print("\n ACTIONABLE GAP OPTIMIZATION & VERDICT:")
    for i, rec in enumerate(results['recommendations'], 1):
        print(f"  {i}. {rec}")

    if args.export:
        print(f"\n[+] Full sorted faculty leaderboard exported to: {args.export}")

if __name__ == '__main__':
    main()
