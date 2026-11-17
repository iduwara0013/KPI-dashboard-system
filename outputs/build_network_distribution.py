from pathlib import Path
from collections import defaultdict
import json
from docx import Document
from docx.shared import Inches, Pt, RGBColor
from docx.oxml import OxmlElement
from docx.oxml.ns import qn

ROOT=Path(__file__).resolve().parent
areas=json.loads((ROOT/'network-plan-entry-data.json').read_text(encoding='utf-8'))
people={}
for line in (ROOT/'network-team-snapshot.txt').read_text(encoding='utf-8-sig').splitlines():
    cells=[c.strip() for c in line.split('|')]
    if len(cells)==5 and cells[0].isdigit():
        e,n,d,r,m=cells;people[e]=dict(name=n,designation=d,role=r,manager=m)
pending={'11438':'16502','16625':'14994','16645':'11473','16646':'11473','16682':'14994','17208':'11473','17235':'11473','17309':'13334','17316':'11473','17359':'11473','17364':'11473','17485':'16918','17486':'16917','17500':'11473','17517':'11473','17530':'16917','17531':'16917','17534':'16917','17568':'11473','17587':'11473'}
assert len(people)==40 and len(pending)==20
for e,m in pending.items():
    assert not people[e]['manager'];people[e]['manager']=m

def node(e,w=100,title=None,children=None):return dict(epf=e,weight=w,title=title,children=children or [])
def split(*rows):return list(rows)
def own(e):return [node(e)]

# Paths are full KPI handoffs; trees below are activity/task allocations.
paths={}
for a in range(1,11):
    for k in (1,2):
        paths[a,k]=(['14673','16503'] if a<=3 else ['14673','16503','11473'] if a==4 else ['14673','16502','14994'] if a==5 else ['14673','16502'] if a in (6,8) else ['14673','11434'] if a==7 and k==1 else ['14673','11434','14631','16917'] if a==7 else ['14673'])
trees={}
for a in range(1,11):
    for k in (1,2):
        owner=paths[a,k][-1]
        for t in (1,2,3):trees[a,k,t]=own(owner)
for a in (1,2):
    trees[a,1,1]=split(node('16503',20,'Review network monitoring and escalation'),node('17611',80,'Perform network monitoring and record alerts'))
    trees[a,1,2]=own('17612');trees[a,1,3]=own('17613')
    for t,e in enumerate(['17611','17612','17613'],1):trees[a,2,t]=own(e)
for k in (1,2):
    for t,e in enumerate(['16503','17611','17612'] if k==1 else ['17613','17611','16503'],1):trees[3,k,t]=own(e)
trees[4,1,1]=split(node('11473',10,'Review facility readiness and exceptions'),node('16648',30,'Perform assigned facility checks'),node('16645',30,'Check assigned UPS and rack conditions'),node('16646',30,'Check assigned environmental conditions'))
trees[4,1,2]=split(node('17496',50,'Coordinate assigned corrective maintenance'),node('17500',50,'Verify assigned facility corrective actions'))
trees[4,1,3]=split(node('17013',60,'Compile inspection and escalation records'),node('17364',40,'Maintain controlled inspection data records'))
trees[4,2,1]=own('11473')
trees[4,2,2]=split(node('11473',25,'Supervise maintenance and accept technical results'),node('17514',15,'Coordinate assigned technical maintenance'),node('17516',15,'Complete assigned equipment service checks'),node('17517',15,'Verify assigned maintenance results'),node('17208',5,'Support approved equipment handling under supervision'),node('17235',5,'Provide authorised maintenance transport support'),node('17316',5,'Prepare maintenance document packs'),node('17359',5,'Prepare authorised maintenance work areas'),node('17568',5,'Assist approved equipment movement under supervision'),node('17587',5,'Organise maintenance records for review'))
trees[4,2,3]=own('16648')
trees[5,1,1]=split(node('14994',20,'Review server monitoring and prioritise alerts'),node('16625',80,'Perform authorised server monitoring checks'))
trees[5,1,2]=split(node('14994',20,'Review incident diagnosis and restoration'),node('16682',80,'Perform authorised technical support actions'))
trees[6,1,1]=split(node('16502',20,'Review ticket prioritisation and assignment'),node('11438',80,'Record and categorise support requests'))
trees[6,1,2]=split(node('16502',20,'Coordinate incident resolution and escalation'),node('14994',80,'Deliver assigned technical support',split(node('14994',20,'Review support diagnosis and technical results'),node('16682',80,'Resolve authorised hardware and software issues'))))
trees[7,1,1]=split(node('11434',10,'Review business application monitoring results'),node('14631',90,'Coordinate application service monitoring',split(node('14631',20,'Review application service risks'),node('16917',80,'Coordinate assigned application checks',split(node('16917',20,'Review functional service checks'),node('17536',80,'Perform assigned application service checks'))))))
trees[7,1,2]=split(node('16918',20,'Coordinate application incident investigation'),node('17528',40,'Investigate assigned functional issues'),node('17485',40,'Investigate authorised technical application issues'))
# 16918 is a direct report of 11434; use one activity branch before its task split.
trees[7,1,2]=[node('16918',100,children=trees[7,1,2])]
trees[7,1,3]=[node('13334',100,children=split(node('13334',60,'Verify application restoration and quality evidence'),node('17309',40,'Check completeness of restoration evidence records')))]
trees[7,2,1]=split(node('16917',20,'Confirm improvement scope and acceptance criteria'),node('17486',40,'Gather assigned functional requirements'),node('17530',40,'Document assigned business process requirements'))
trees[7,2,2]=split(node('16917',20,'Coordinate approved application change delivery'),node('17531',40,'Complete assigned functional change work'),node('17534',40,'Complete assigned configuration and verification work'))
trees[7,2,3]=split(node('16917',20,'Review acceptance and authorise handover'),node('17536',80,'Execute assigned tests and prepare handover evidence'))

shares=defaultdict(float);activity_rows={}
def flatten(rows,assigner,base,prefix=''):
    assert sum(n['weight'] for n in rows)==100
    out=[]
    for i,n in enumerate(rows,1):
        e=n['epf'];assert e==assigner or people[e]['manager']==assigner,(assigner,e)
        number=f'{prefix}{i}';amount=base*n['weight']/100
        out.append((number,n,e,assigner,amount))
        if n['children']:out+=flatten(n['children'],e,amount,number+'.')
        else:shares[e]+=amount
    return out
for a,area in enumerate(areas,1):
    for k,kpi in enumerate(area['kpis'],1):
        path=paths[a,k]
        for boss,e in zip(path,path[1:]):assert people[e]['manager']==boss
        for t,act in enumerate(kpi['activities'],1):
            activity_rows[a,k,t]=flatten(trees[a,k,t],path[-1],area['weight']*kpi['weight']/100*act['weight']/100)
assert len(shares)==40 and abs(sum(shares.values())-100)<1e-8

doc=Document();sec=doc.sections[0]
sec.page_width=Inches(8.5);sec.page_height=Inches(11)
sec.top_margin=sec.bottom_margin=Inches(.6);sec.left_margin=sec.right_margin=Inches(.6)
normal=doc.styles['Normal'];normal.font.name='Calibri';normal.font.size=Pt(10.5)
normal.paragraph_format.space_after=Pt(5)
for key,size in [('Title',24),('Heading 1',17),('Heading 2',12),('Heading 3',11)]:
    st=doc.styles[key];st.font.name='Calibri';st.font.size=Pt(size);st.font.color.rgb=RGBColor(0,0,0)
    st.paragraph_format.space_before=Pt(8);st.paragraph_format.space_after=Pt(5);st.paragraph_format.keep_with_next=True
for st in doc.styles:
    if st.type==1:
        for e in list(st.element.xpath('./w:pPr/w:pBdr')):e.getparent().remove(e)
doc.core_properties.title='IT Network Plan Team Distribution Guide'
def p(s,style=None):return doc.add_paragraph(s,style)
def h(s,l=1):return doc.add_heading(s,l)
def page(s):doc.add_page_break();h(s)
def person(e):return f"{e}  {people[e]['name']}"
def fmt(x):return f'{x:.4f}'.rstrip('0').rstrip('.')+'%'
def table(rows,widths):
    t=doc.add_table(rows=0,cols=len(widths));t.autofit=False
    for c,w in zip(t.columns,widths):c.width=Inches(w)
    b=OxmlElement('w:tblBorders')
    for edge in ['top','left','bottom','right','insideH','insideV']:
        e=OxmlElement('w:'+edge);e.set(qn('w:val'),'single');e.set(qn('w:sz'),'4');e.set(qn('w:color'),'D9D9D9');b.append(e)
    t._tbl.tblPr.append(b)
    for i,row in enumerate(rows):
        r=t.add_row();r._tr.get_or_add_trPr().append(OxmlElement('w:cantSplit'))
        if not i:r._tr.get_or_add_trPr().append(OxmlElement('w:tblHeader'))
        for j,v in enumerate(row):
            c=r.cells[j];c.width=Inches(widths[j]);c.vertical_alignment=1
            pa=c.paragraphs[0];pa.paragraph_format.space_before=Pt(3);pa.paragraph_format.space_after=Pt(3);pa.paragraph_format.line_spacing=1
            run=pa.add_run(str(v));run.font.size=Pt(9.5)
            if not i:
                run.bold=True;s=OxmlElement('w:shd');s.set(qn('w:fill'),'EDF2F7');c._tc.get_or_add_tcPr().append(s)
    p('');return t
footer=sec.footer.paragraphs[0];footer.alignment=2
footer.add_run('IT network plan team distribution   |   ')
f=OxmlElement('w:fldSimple');f.set(qn('w:instr'),'PAGE');footer._p.append(f)

p('IT Network Plan Team Distribution Guide','Title')
p('2027 Information Systems plan for CIO EPF 14673')
p('Use this guide with the IT Network Performance Plan Entry Guide. It distributes the same 10 KPAs, 20 KPIs and 60 activities through managers to individual team members. The allocation below is a proposed workflow example, not an approved staffing or salary decision.')
p('The database was read on 17 November 2026. Information Systems has 40 members: 20 are connected to the CIO reporting tree, including the CIO, and 20 have no reporting manager. All 40 receive a defined work share in the expanded example only after the proposed missing reporting links are approved and configured. No live records were changed.')
h('Use the guide in this order')
for s in ['Confirm the existing reporting links and approve or revise the 20 proposed links on the next pages.','Enter and publish the CIO plan using the previous entry guide. Keep its system generated IDs.','CIO assigns each complete KPI to the first manager in the handoff table. A receiving manager forwards a complete KPI only where a further handoff is listed.','At the final KPI owner, open each assigned activity and create the stated activity owner or task breakdown.','Publish or agree personal allocations separately, then test progress, evidence submission and review from the lowest employee upward.']:p(s,'List Number')
h('Work ownership and percentages')
p('A full KPI becomes a Linked KPA for the receiving manager. The source KPA, source KPI, targets and activities remain traceable. Individual activity assignments retain their source KPA and KPI; they do not create another full KPI or a new independent KPA.')
p('Task percentages in this guide are relative to their immediate parent. They are not automatically the employee personal-plan weight. The original CIO weights and targets remain unchanged when work is delegated.')
p('Keep the proposed final delivery date 2027-12-10 from the entry guide unless an approved deadline is agreed. Monthly and quarterly work still follows its monitoring cadence. Assigning office or support duties does not authorise technical access or changes.')

page('Reporting links to confirm')
p('These 19 links already exist in the database. CIO 14673 is the root. In particular, 11473 currently reports to 16503. This guide follows that saved relationship; correct it first if the approved company hierarchy differs.')
current=[e for e in people if e not in pending and e!='14673']
table([['EPF and person','Current manager EPF']]+[[person(e),people[e]['manager']] for e in current],[5.8,1.3])

page('Proposed links for the remaining team')
p('These 20 reporting links do not yet exist. They are proposals based on the recorded designations and the routes needed for this example. The HOF or administrator must approve the actual supervisor and capability before configuring them. Support staff receive support deliverables, not server or network administration privileges.')
table([['EPF and person','Proposed manager EPF']]+[[person(e),m] for e,m in pending.items()],[5.8,1.3])
p('If the links are not approved, use only the connected team. Keep the pending person work with the assigning manager or reallocate it among eligible direct reports so each saved breakdown still totals 100%. Do not bypass the reporting checks or select an unrelated person.')

page('Complete KPI handoff register')
p('Every row identifies one existing KPI from the entry guide. Read the EPF route from left to right. Each arrow is a separate full KPI assignment made by the current owner. Complete these handoffs before creating activity tasks or recording progress.')
rows=[['Reference and KPI title','Full KPI route EPFs','CIO share']]
for a,area in enumerate(areas,1):
    for k,kpi in enumerate(area['kpis'],1):rows.append([f'{a:02d}.{k:02d}  {kpi["title"]}',' > '.join(paths[a,k]) if len(paths[a,k])>1 else '14673 keeps own work',fmt(area['weight']*kpi['weight']/100)])
table(rows,[4.25,2.15,.7])

page('How each level performs the assignment')
h('CIO and plan editor',2)
p('The Plan Editor enters and publishes the CIO plan. CIO opens Plan and assignments, selects Information Systems and 2027, then Assign work. Use Assign KPI on each KPI row and choose the first manager in the handoff register. For KPAs 9 and 10 keep the work as CIO own work and create or confirm own tasks through the available own-work controls.')
h('Receiving manager',2)
p('Open My work > Planned work > Linked KPAs. Find the exact source KPI title. If the route continues to another manager, use Assign full KPI, choose the next direct-report manager and save. All three activities travel together. Do not forward a full KPI after dividing or starting its work. An eligible unstarted handoff has Edit / cancel assignment controls.')
p('At the last manager on the route, open Assigned activities and expand source KPA > KPI > activity. A manager responsibility remains here until they explicitly keep or allocate it. It must not become an own executable task merely because the activity was received.')
h('Single activity owner or multiple tasks',2)
p('If a page below has one row at 100%, use Assign activity / keep with me and select that person. A manager recipient receives the activity as a responsibility. If they will execute it, they explicitly Keep with me. An employee recipient performs the resulting task and has no Divide work action.')
p('For several rows, use Create task breakdown and enter the task title, owner EPF, weight and deadline shown. Save only at 100%. Indented numbers such as 1.1 are a second breakdown performed by the manager who received row 1. Their percentages sum to 100% of row 1, not 100% of the original activity.')
p('A manager receiving a parent task opens Divide work and allocates its child tasks. If the work is still an activity responsibility, use Assigned activities and Create task breakdown. Follow the shown owner chain; never assign directly to a grandchild. Copy task titles without the bracketed [divide next] instruction.')
h('Copyable assignment instructions',2)
p('Complete the agreed deliverable under the linked source KPA and KPI. Retain dated evidence, follow approved change controls and escalate blockers to your assigning supervisor. Submit completed work for review. Do not change scope, deadline or weights without approval.')

for a,area in enumerate(areas,1):
    for k,kpi in enumerate(area['kpis'],1):
        page(f'KPA {a:02d} KPI {k:02d} team allocation')
        p(area['title']+'  |  '+fmt(area['weight'])+' of CIO plan')
        h(kpi['title'],2)
        p('KPI weight within KPA: '+fmt(kpi['weight'])+'   |   CIO plan share: '+fmt(area['weight']*kpi['weight']/100))
        p('Full KPI route: '+' > '.join(paths[a,k])+'. Final activity allocator: '+person(paths[a,k][-1])+'.')
        for t,act in enumerate(kpi['activities'],1):
            h(f'Activity {t} '+act['title'],2)
            p('Activity weight within KPI: '+fmt(act['weight'])+'   |   Due date: '+act['due'])
            rows=[['Step','Task title to enter','Owner EPF','Weight','Assigning EPF']]
            for number,n,e,assigner,amount in activity_rows[a,k,t]:
                title=n['title'] or act['title']
                if n['children']:title+=' [divide next]'
                rows.append([number,title,e+(' *' if e in pending else ''),fmt(n['weight']),assigner])
            table(rows,[.65,3.8,.85,.6,1.2])
        p('Task instructions: deliver the named part of the parent activity and attach dated evidence. Use the parent activity notes in the entry guide for the required verification records. Rows marked * require the proposed reporting link first.')

for start in (0,20):
    page('Individual work shares '+('1 to 20' if start==0 else '21 to 40'))
    p('These are terminal execution shares of the original CIO plan after the complete expanded example is allocated. Manager coordination and own-work rows are counted; parent responsibilities are not counted again. These figures are not salary percentages or personal-plan allocation percentages.')
    ordered=list(people)
    table([['EPF and person','Execution share','Link status']]+[[person(e),fmt(shares[e]),'Proposed' if e in pending else 'Existing'] for e in ordered[start:start+20]],[5.0,1.0,1.1])
    if start==20:p('All 40 execution shares total 100%. Display rounding may cause a small visible difference when manually adding rounded figures.')

page('End to end verification example')
p('Use one test task before recording wider progress. These expected values assume the entry guide weights, this allocation, no extra-work replacement and no other approved tasks. Submit realistic evidence only; do not submit a test result as completed production work.')
h('Example A employee to network manager to CIO',2)
p('Use KPA 1 KPI 1 Activity 2: Troubleshoot and resolve LAN and Wi-Fi connectivity issues. CIO assigns the full KPI to 16503. Manager 16503 assigns this activity at 100% to employee 17612. Employee opens My tasks, updates progress and submits completed work. Manager 16503 reviews the evidence.')
p('After approval: employee task = 100%; parent activity = 100%; KPI approved work completion = 40% because this activity weighs 40% of that KPI. CIO weighted plan contribution = 25% x 70% x 40% = 7%. This does not mean LAN availability was measured at 40% or 100%; record the actual availability separately against the 99.5% target.')
h('Example B deeper manager chain',2)
p('KPA 7 KPI 2 travels 14673 > 11434 > 14631 > 16917. Its Activity 3 is split between 16917 own task 20% and employee 17536 at 80%. If only 17536 work is approved, the activity reaches 80%, this KPI reaches 24% (30% x 80%), and the CIO receives 0.48% (5% x 40% x 30% x 80%). Parent manager responsibilities roll up this approved work; they do not submit duplicate copies of the employee evidence to create another contribution.')
h('Personal allocations',2)
p('Each person must review My allocation based on their actual received responsibilities. Publish a valid 100% allocation where the workflow requires it. A person with one responsibility may give it 100% of their personal plan; a person with several divides 100% among those responsibilities. That personal normalisation does not change the original CIO contribution shown above.')
h('Review and exception checks',2)
for s in ['Reported progress alone does not create confirmed completion. The assigning reviewer must approve evidence; HOF own work uses evidence-based self confirmation.','For manager own work inside a delegated branch, the assigning supervisor reviews it. Use the Reviewer shown on the task screen as the authority for the actual review path.','Check that an employee cannot divide work and that a manager can choose only eligible direct reports.','Check that every activity breakdown totals 100% and every task retains the original source KPA and KPI.','After work starts, ownership and weight changes may be locked. Use the approved correction workflow rather than deleting completed history.','Extra work stays separate unless a reviewed replacement is approved. Do not add the same contribution twice. Deadline penalties affect timeliness according to configured rules, not the KPI target definition.']:p(s,'List Bullet')

out=ROOT/'2027 IT Network Team Distribution Guide.docx';doc.save(out)
(ROOT/'network-team-distribution-data.json').write_text(json.dumps(dict(paths={f'{a}.{k}':v for (a,k),v in paths.items()},trees={f'{a}.{k}.{t}':v for (a,k,t),v in trees.items()},shares=shares,pending=pending),indent=2),encoding='utf-8')
print(out);print('Validated 40 people, 20 KPIs, 60 activities; all parent allocations 100%; terminal shares',sum(shares.values()))
