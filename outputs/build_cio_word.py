import json
from pathlib import Path
from docx import Document
from docx.shared import Inches, Pt, RGBColor
from docx.oxml import OxmlElement
from docx.oxml.ns import qn

ROOT = Path(__file__).resolve().parents[1]
plan = json.loads((ROOT / 'backend/tests/Fixtures/cio-workflow-example.json').read_text(encoding='utf-8'))
team = json.loads((ROOT / 'backend/tests/Fixtures/cio-team-workflow.json').read_text(encoding='utf-8'))
people = {p['epf']: p for p in team['people']}
doc = Document()
sec = doc.sections[0]
sec.page_width, sec.page_height = Inches(8.27), Inches(11.69)
sec.top_margin = sec.bottom_margin = Inches(.6)
sec.left_margin = sec.right_margin = Inches(.65)
normal = doc.styles['Normal']
normal.font.name = 'Calibri'
normal.font.size = Pt(10.5)
normal.paragraph_format.space_after = Pt(5)
normal.paragraph_format.line_spacing = 1.05
for key, size in [('Title', 25), ('Heading 1', 18), ('Heading 2', 13), ('Heading 3', 11)]:
    st = doc.styles[key]
    st.font.name = 'Calibri'
    st.font.size = Pt(size)
    st.font.color.rgb = RGBColor.from_string('000000' if key == 'Title' else '243F5D')
    st.paragraph_format.space_before = Pt(9)
    st.paragraph_format.space_after = Pt(5)
    st.paragraph_format.keep_with_next = True
doc.core_properties.title = 'CIO full team plan entry and workflow guide'
doc.core_properties.subject = 'Copy and paste fields for the Information Systems performance plan'
doc.core_properties.author = 'CPSTL'

def p(text, style=None):
    return doc.add_paragraph(text, style)

def h(text, level=1):
    return doc.add_heading(text, level)

def page():
    doc.add_page_break()

def table(rows, widths=None, header=False):
    t = doc.add_table(rows=0, cols=len(rows[0]))
    t.autofit = False
    if widths:
        for c, width in zip(t.columns, widths):
            c.width = Inches(width)
    for i, data in enumerate(rows):
        row = t.add_row()
        keep = OxmlElement('w:cantSplit')
        row._tr.get_or_add_trPr().append(keep)
        if header and i == 0:
            repeat = OxmlElement('w:tblHeader')
            row._tr.get_or_add_trPr().append(repeat)
        for j, value in enumerate(data):
            cell = row.cells[j]
            if widths:
                cell.width = Inches(widths[j])
            para = cell.paragraphs[0]
            para.paragraph_format.space_after = Pt(2)
            para.paragraph_format.space_before = Pt(2)
            para.paragraph_format.line_spacing = 1
            run = para.add_run(str(value))
            run.font.size = Pt(10)
            if (header and i == 0) or (not header and j == 0):
                run.bold = True
                shade = OxmlElement('w:shd')
                shade.set(qn('w:fill'), 'EDF2F7')
                cell._tc.get_or_add_tcPr().append(shade)
            margins = OxmlElement('w:tcMar')
            for tag, size in [('top','28'), ('bottom','28'), ('left','70'), ('right','70')]:
                element = OxmlElement('w:'+tag)
                element.set(qn('w:w'),size)
                element.set(qn('w:type'),'dxa')
                margins.append(element)
            cell._tc.get_or_add_tcPr().append(margins)
    p('').paragraph_format.space_after = Pt(0)
    return t

def fields(items):
    return table(items, [1.45, 5.5])

def person(epf):
    return epf + '  ' + people[epf]['name']

def weight(value):
    return f'{value*100:g}%'

p('CIO full team plan entry and workflow guide', 'Title')
p('Information Systems | Performance year 2027')
p('Use this document to enter the CIO plan and distribute it to every person currently connected in the department reporting structure. Copy values from the right column of each field table. Titles, IDs and notes are editable Word text, not screenshots.')
p('Scope: 20 people including CIO, 10 KPAs, 10 KPIs and 42 activities. The complete allocation creates 62 task records, including 58 executable leaf tasks. All 20 people receive their own work as well as any supervisory responsibility.')
h('How to copy the fields', 2)
p('Copy only the value in the right-hand cell. For percentage inputs enter the number, for example 25 for 25%. Choose the stated option in dropdowns. Keep EPF numbers and IDs exactly as written. Notes and task evidence are supplied where useful.')
p('The weights, targets, deadlines and task splits are proposed workflow-test values. Confirm them before operational use. KPA and KPI titles and the activity list come from the CIO sheet of KPINEW.xlsx, cells A3:C12. KRA in that workbook is entered as KPA in the system.')
h('Create the CIO plan', 2)
fields([('Department','Information Systems'),('Find owner by EPF','14673'),('Confirm owner','G A S GAJADEERA'),('Plan title','2027 CIO full team workflow test'),('Performance year','2027'),('Plan ID','TEST-CIO-TEAM-2027-14673')])
p('Use an unused performance year. If CIO already has a 2027 plan, choose another year and change every date consistently. Create the draft first, enter Sections 3 to 12, then save and publish. This document does not create records in the live database.')

page(); h('1 Full team and reporting links')
table([['EPF','Name','Reports to']] + [[x['epf'],x['name'],x['reports_to'] or 'Executive structure'] for x in team['people']], [.7,5.15,1.1],True)
p('These are the saved reporting links used for the example. Head of Data Center 11473 currently reports to Network 16503. Correct that link first if the real hierarchy differs. The other 20 department members have no reporting manager assigned and are not included until their links are configured.')
h('2 Whole plan allocation')
table([['Owner or branch','Original CIO plan share'],['CIO own work 14673','10%'],['Solutions 11434 and team','40%'],['System Admin 16502 and team','40%'],['Network 16503 and team','10%'],['Total','100%']], [4.9,2.05],True)
p('CIO assigns full KPIs to the listed branch owners. The full KPI appears as a Linked KPA for the recipient. Original activity weights are preserved. Personal allocation percentages are normalized separately for each person.')

for i, kpa in enumerate(plan['kpas'],1):
    page()
    kpi = kpa['kpis'][0]
    owner = team['kpi_owners'][kpi['code']]
    h(f'{i+2} {kpa["title"]}')
    h('KPA fields',2)
    fields([('KPA ID',kpa['code']),('KPA title',kpa['title']),('Department','Information Systems'),('KPA weight',weight(kpa['weight']))])
    h('KPI fields',2)
    fields([('KPI ID',kpi['code']),('KPI title',kpi['title']),('Weight within KPA','100%'),('Target value',kpi['target_value']),('Target unit','Percent (%)' if kpi['target_unit']=='percent' else 'count'),('Monitoring frequency',kpi['frequency']),('Target direction','Higher is better'),('KPI notes',kpi['notes'].split(': ',1)[-1].replace('Confirm production baseline and target before operational use.','').strip())])
    p('Full KPI assignment: '+person(owner)+'. Deadline: 2027-12-10. '+('Choose My own work for CIO.' if owner=='14673' else 'CIO chooses Assign KPI and this responsible manager.'))
    h('Activity fields',2)
    p('Activity deadline for every row: 2027-12-15. Copy the title from its own cell. Activity IDs are listed separately.')
    rows=[['Activity ID','Activity title','Weight','Delivery']]
    for a in kpi['activities']:
        mode = 'Team template '+team['specials'][a['code']]['template'] if a['code'] in team['specials'] else 'Own task 100%\nEPF '+owner
        rows.append([a['code'],a['title'],weight(a['weight']),mode])
    table(rows,[1.25,3.35,.65,1.7],True)

page();h('13 Assign all KPIs and retained activities')
p('After publishing, CIO opens Plan and assignments. Assign each full KPI as listed below. Set the assignment deadline to 2027-12-10 and use the common instruction below. Individual activity overrides are not needed for this baseline scenario.')
fields([('Assignment instructions','Deliver the agreed activities, keep evidence for each deliverable, and divide team work through the saved reporting structure. Use the specified weights and deadlines.')])
table([['KPI ID','Responsible owner EPF','CIO plan share']] + [[k['kpis'][0]['code'],team['kpi_owners'][k['kpis'][0]['code']],weight(k['weight'])] for k in plan['kpas']], [2.35,2.7,1.9],True)
p('The three team activities are CIO-ACT-05-03, CIO-ACT-03-01 and CIO-ACT-07-04. Every other activity remains with its full KPI owner. There are 39 retained activities.')
h('Retained manager activities',2)
p('For each retained manager activity, open Assigned activities and choose Divide work or Add tasks. Enter a single task using the parent activity title. Choose Keep with or My own task for that manager, weight 100%, deadline 2027-12-10. CIO remains the reviewer. Save the 100% breakdown. CIO own Financial Management activities already create own tasks and are confirmed by CIO with evidence.')
h('Copyable retained task fields',2)
fields([('Task title','Copy the exact parent activity title from its activity fields'),('Task owner','The full KPI owner listed above'),('Task weight','100%'),('Task deadline','2027-12-10'),('Task instructions','Complete the agreed activity and provide evidence that the deliverable has been verified.')])

page();h('14 Team task fields')
p('Build each parent task set together and save only when its sibling weights total 100%. Create the top-level group before the nested groups. Copy titles exactly. Every task below has deadline 2027-12-10.')
leaf_shares=[]
for kpa in plan['kpas']:
    for a in kpa['kpis'][0]['activities']:
        if a['code'] not in team['specials']:
            leaf_shares.append((team['kpi_owners'][kpa['kpis'][0]['code']],kpa['weight']*a['weight']))
for code,special in team['specials'].items():
    if special['template'] == 'A': page()
    kpa=next(k for k in plan['kpas'] if any(a['code']==code for a in k['kpis'][0]['activities']))
    act=next(a for a in kpa['kpis'][0]['activities'] if a['code']==code)
    h('Template '+special['template'],2)
    p(code+'  '+act['title']+'. Activity owner: '+person(special['owner'])+'. Original CIO plan contribution: '+weight(kpa['weight']*act['weight'])+'.')
    def groups(tasks,responsible,reviewer,parent_title,parent_share):
        h('Divide '+parent_title,3)
        p('Responsible person: '+person(responsible)+'. Open this person’s activity or received task and choose Divide work. The editor may enter the same group through Work assignments.')
        rows=[['Task title','Owner EPF','Weight','Reviewer EPF']]
        for t in tasks:
            review=reviewer if t['epf']==responsible else responsible
            rows.append([t['title'],t['epf'],weight(t['weight']),review])
            if not t.get('children'):leaf_shares.append((t['epf'],parent_share*t['weight']))
        table(rows,[3.8,1.05,.85,1.25],True)
        for t in tasks:
            if t.get('children'):
                review=reviewer if t['epf']==responsible else responsible
                groups(t['children'],t['epf'],review,t['title'],parent_share*t['weight'])
    groups(special['tasks'],special['owner'],'14673',act['title'],kpa['weight']*act['weight'])
    p('Task instructions: Complete the assigned deliverable, record the verification result and submit supporting evidence to the designated reviewer.')

page();h('15 Check every person has work')
p('The following terminal own-work shares add to 100% of the original CIO plan. A manager’s supervised branch is already represented by its children, so do not add that supervised scope again. This table is not a list of personal allocation percentages.')
table([['EPF','Name','Terminal share']] + [[e,x['name'],weight(sum(s for epf,s in leaf_shares if epf==e))] for e,x in people.items()] + [['','Total','100%']], [.75,5.0,1.2],True)
assert len(leaf_shares)==58
assert abs(sum(v for _,v in leaf_shares)-1)<1e-8
assert set(e for e,_ in leaf_shares)==set(people)

page();h('16 Submission and review fields')
p('Every executable leaf is completed by its assignee and reviewed by the supervisor listed in Section 14. A retained manager task is reviewed by CIO. CIO confirms own work with evidence. The editor does not become a reviewer merely by entering the assignment.')
fields([('Reported progress','100'),('Progress update','The assigned deliverable is complete. Verification checks have been carried out and the evidence is ready for review.'),('Evidence summary','The agreed deliverable has been completed and verified. The supporting record explains the result, verification checks and any remaining follow-up.'),('Supporting evidence link','Enter the real http or https evidence URL accessible to the reviewer, or leave blank'),('Needs changes comment','Please add the missing verification results and explain how the reviewer can confirm the completed deliverable.'),('Resubmission summary','The requested verification results have been added. The updated evidence shows the completed deliverable and the checks performed.'),('Approval comment','The submitted deliverable and verification evidence have been reviewed and accepted.')])
p('Use these as starting text, then replace them with the actual result and evidence. Do not approve placeholders. Reporting progress or submitting evidence does not itself grant approved completion.')
h('Test checkpoints',2)
table([['Checkpoint','Expected confirmed completion'],['Everything allocated and no work approved','0% for all participants'],['Engineer 17536 only approved','CIO 1.176%; SAP 80%; Architect 56%; Solutions personal 2.94%'],['Also approve Lead Systems Engineer 14994 and System Executive 17611','CIO 7.876%; Solutions 2.94%; System Admin 16%; Network 3%'],['Complete all three team activities only','CIO 15.5%; Solutions 15%; System Admin 20%; Network 15%'],['Complete all 58 leaf tasks','All 20 participants and CIO reach 100%']], [3.7,3.25],True)
p('The three selected leaf tasks are Configure and verify solution, Infrastructure health and reliability review, and Network service investigation. The three leaf assignees each reach personal 100% because they have only that one responsibility in this baseline scenario. A two-decimal screen may show CIO 7.88% instead of 7.876%.')

page();h('17 Full team execution checklist')
for text in [
    'Create the CIO draft and enter all 10 KPAs, 10 KPIs and 42 activities from Sections 3 to 12. Save and publish.',
    'CIO assigns all 10 full KPIs using Section 13. Solutions receives four Linked KPAs, System Admin three, and Network two. CIO retains the Financial Management KPI.',
    'Create the 39 retained own-task allocations and all groups in templates S, A and N. The expected result is 42 activity assignments and 62 active task records, of which 58 are executable leaves.',
    'Sign in to each connected account and verify that its assigned work is visible. Keep the same year and department filters when comparing percentages.',
    'Submit one selected leaf as incomplete evidence. The real reviewer requests changes. Correct the evidence, resubmit and approve. Confirm that credit is granted only after approval.',
    'Approve the three selected leaves and compare the checkpoint values in Section 16 with the CIO, manager, editor and corresponding MD weighted work views.',
    'Complete all remaining leaves. Parents roll up automatically. Do not submit or approve a parent again after its children have completed it.',
    'Confirm that every participant reaches 100% in this isolated full-team scenario. Record actual KPI outcomes separately; approved tasks do not by themselves prove uptime or customer satisfaction targets.'
]:p(text,'List Number')
h('Editor controls and work changes',2)
p('Plan editor opens Work assignments to see the department’s delegated KPIs, activities and nested tasks. Use Edit KPI assignment, Edit activity assignment, Add tasks or Divide work before the relevant breakdown is locked. Use Edit plan / add activities to revise original plan scope, save and publish, then review any affected assignments. Mixed-department plans are read only when the editor covers only part of their scope.')
p('Started breakdowns stay locked and history is retained. A new incomplete assignment changes automatic personal weights according to its contribution, not an assumed equal split. For example, completed contribution 1.176 and new incomplete contribution 3 produce personal completion 1.176 / 4.176 = 28.16%. Review an approved custom allocation separately. Extra work stays separate unless the authorized replacement process is approved.')
p('Sources: KPINEW.xlsx, CIO sheet A3:C12; the saved Information Systems reporting structure reviewed on 16 November 2026. Weights, targets, deadlines and distribution are the proposed full-team workflow example. Reporting links for the 20 unconnected department members must be configured before extending it to them.')

footer = sec.footer.paragraphs[0]
footer.alignment = 2
footer.paragraph_format.space_after = Pt(0)
run=footer.add_run('Page ')
run.font.size=Pt(9)
field=OxmlElement('w:fldSimple');field.set(qn('w:instr'),'PAGE');footer._p.append(field)
# Remove inherited decorative title borders from the default Word template.
for tree in [doc.styles._element, doc._element]:
    for border in list(tree.iter(qn('w:pBdr'))):
        border.getparent().remove(border)
dest = ROOT/'outputs/CIO Full Team Plan Entry Guide.docx'
doc.save(dest)
print(str(dest))
