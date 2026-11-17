from pathlib import Path
from docx import Document
from docx.shared import Inches, Pt, RGBColor
from docx.oxml import OxmlElement
from docx.oxml.ns import qn
import json

ROOT = Path(__file__).resolve().parent
areas = []

def area(title, weight, category, notes):
    areas.append(dict(title=title, weight=weight, category=category, notes=notes, kpis=[]))

def metric(title, weight, target, unit, frequency, direction, notes, activities):
    parsed = []
    for line in activities.strip().splitlines():
        value, description, evidence = line.strip().split('|')
        parsed.append(dict(title=description.strip(), weight=int(value), due='2027-12-10', notes=evidence.strip()))
    assert sum(a['weight'] for a in parsed) == 100
    areas[-1]['kpis'].append(dict(title=title, weight=weight, target=target, unit=unit, frequency=frequency, direction=direction, notes=notes, activities=parsed))

area('LAN and Wireless Network Operations',25,'Internal Processes','Maintain dependable LAN and Wi-Fi services and deliver approved network improvements across company locations.')
metric('Maintain LAN and Wi-Fi service availability',70,99.5,'Percent (%)','Monthly','Higher is better','Measure availability of agreed in-scope LAN and Wi-Fi services during each month. Availability = available service minutes / scheduled service minutes x 100. Record monitoring coverage, outages and any approved exclusions.', '''40|Monitor LAN switches, access points and critical network links|Maintain dated monitoring records with device and site references and identified faults.
40|Troubleshoot and resolve LAN and Wi-Fi connectivity issues|Record incident references, diagnosis, corrective action and successful connectivity verification.
20|Review network availability and recurring faults|Prepare the monthly availability summary and recurring-fault action list.''')
metric('Complete planned LAN and Wi-Fi improvements',30,100,'Percent (%)','Quarterly','Higher is better','Measure accepted improvement milestones completed / milestones due in the quarter x 100. Agree the milestone list and acceptance evidence before the quarter starts. An unapproved scope change does not change the denominator.', '''30|Assess network coverage, cabling and port capacity requirements|Record site findings, capacity requirements and the approved improvement scope.
50|Install or upgrade switches, access points and network cabling|Keep approved change records, equipment details and installation evidence.
20|Test connectivity and update network diagrams|Attach connectivity test results and the updated approved network diagram.''')

area('WAN and Internet Connectivity',15,'Internal Processes','Maintain connectivity between company sites and manage internet performance and capacity.')
metric('Maintain reliable connectivity between company locations',60,99.5,'Percent (%)','Monthly','Higher is better','Measure monthly availability of agreed WAN services using scheduled service minutes as the denominator. Keep site and provider outage records. Agree planned-maintenance exclusions before measuring.', '''40|Monitor WAN links and site-to-site connectivity|Record link status, affected locations and monitoring alerts.
40|Coordinate link-fault resolution with service providers|Maintain provider ticket numbers, escalation times and restoration confirmation.
20|Test backup links and connectivity failover|Record the approved failover test, result and any recovery actions.''')
metric('Improve internet bandwidth utilisation and performance',40,80,'Percent (%)','Monthly','Lower is better','Measure the monthly 95th-percentile utilisation of each in-scope internet link; report the highest value. Utilisation = used bandwidth / provisioned bandwidth x 100. The proposed upper target is 80 percent. Evaluate availability separately.', '''30|Review bandwidth usage and identify network bottlenecks|Keep bandwidth reports, measurement periods and identified congestion points.
40|Optimise traffic prioritisation and bandwidth allocation|Document approved traffic policy changes and before-and-after measurements.
30|Review internet performance and recommend capacity changes|Prepare a capacity review with evidence and prioritised recommendations.''')

area('Network Security and Access Control',20,'Internal Processes','Maintain secure network configurations, controlled remote access and timely network vulnerability remediation.')
metric('Maintain secure firewall and network configurations',60,100,'Percent (%)','Monthly','Higher is better','Measure scheduled firewall, VPN and configuration reviews completed / reviews due in the month x 100. Define the device and access-review scope in advance. Completion requires recorded findings and approved actions.', '''40|Review firewall rules, VPN access and network security logs|Record review dates, rules or access reviewed, findings and required approvals.
40|Apply approved firewall and network security changes|Retain change approvals, implementation records and verification results.
20|Back up configurations and document security changes|Keep configuration backup references and updated configuration change records.''')
metric('Resolve network vulnerabilities and unauthorised access risks',40,95,'Percent (%)','Monthly','Higher is better','Measure remediation actions closed within their agreed risk-based deadlines / remediation actions due in the month x 100. Closure requires verification. When no actions are due, record no applicable actions and follow the approved reporting rule; do not invent a percentage.', '''30|Identify vulnerable network devices and insecure configurations|Record authorised assessment findings, affected assets and agreed risk priorities.
50|Apply approved firmware updates and remediation actions|Maintain approvals, update references, remediation dates and rollback arrangements.
20|Verify remediation and review privileged network access|Attach verification results and the privileged-access review record.''')

area('Data Centre Operations and Readiness',10,'Internal Processes','Keep data centre facilities operational and complete preventive maintenance with verifiable records.')
metric('Maintain operational readiness of data centre facilities',60,100,'Percent (%)','Monthly','Higher is better','Measure scheduled readiness checks completed / checks due in the month x 100. Use an approved checklist covering rack equipment, UPS status and environmental conditions. Track unresolved risks separately.', '''40|Check rack equipment, UPS status and environmental conditions|Complete dated inspection checklists and record abnormal conditions.
40|Coordinate corrective maintenance for identified facility issues|Keep maintenance requests, assigned owners and corrective-action evidence.
20|Maintain inspection records and escalate unresolved risks|Update the inspection register and record escalations with responsible owners.''')
metric('Complete preventive maintenance of data centre equipment',40,100,'Percent (%)','Quarterly','Higher is better','Measure scheduled maintenance jobs completed and verified / jobs due in the quarter x 100. Keep an approved schedule and record deferred jobs with their authorisation.', '''20|Prepare and maintain the preventive maintenance schedule|Keep the approved equipment list, maintenance dates and service responsibilities.
60|Carry out or coordinate scheduled equipment maintenance|Record completed service jobs, vendor reports and any identified defects.
20|Verify equipment operation and update maintenance records|Attach post-maintenance checks and update the equipment maintenance history.''')

area('Server and System Administration',10,'Internal Processes','Maintain critical server services and carry out approved patching, account reviews and capacity management.')
metric('Maintain availability of critical servers and system services',60,99.5,'Percent (%)','Monthly','Higher is better','Measure monthly availability of agreed critical server services. Availability = available service minutes / scheduled service minutes x 100. Keep the service list, outage records and agreed maintenance exclusions.', '''40|Monitor server health, storage capacity and critical services|Keep monitoring results, capacity alerts and service-health records.
40|Resolve server and operating-system incidents|Record incidents, diagnosis, corrective actions and restoration verification.
20|Review capacity trends and plan required improvements|Produce a capacity review with storage, processing and service-growth actions.''')
metric('Complete scheduled server patching and access reviews',40,95,'Percent (%)','Monthly','Higher is better','Measure approved patching and access-review actions completed by their due dates / actions due in the month x 100. Define the action list and due dates in advance and verify completion.', '''30|Review required patches and prepare approved change requests|Maintain the patch assessment, affected systems and approved change requests.
50|Apply updates and verify system functionality|Record update results, service tests and any rollback or exception decisions.
20|Review administrator accounts and update system records|Keep account review approvals and updated system administration records.''')

area('IT Service Desk and User Support',5,'Customer','Provide traceable IT support and reduce recurring incidents through verified corrective action.')
metric('Resolve IT support requests within agreed service timelines',70,90,'Percent (%)','Monthly','Higher is better','Measure support requests resolved within their agreed SLA / resolved requests assessed in the month x 100. Use agreed priority-based timelines and ticket timestamps. Record exclusions and reopened-ticket handling consistently.', '''20|Record, categorise and prioritise user support requests|Maintain support tickets with requester, priority, assigned owner and timestamps.
60|Diagnose and resolve assigned hardware and software issues|Record diagnosis, work completed and resolution evidence for each ticket.
20|Confirm resolution with users and close support records|Record user confirmation or the approved closure basis and close the ticket.''')
metric('Reduce repeated user-support incidents',30,5,'count','Monthly','Lower is better','Count repeat incidents in the month using an agreed repeat-incident definition and root-cause register. The proposed target is no more than 5 repeat incidents per month. Record zero when verified; do not omit the result.', '''30|Analyse frequently reported IT support issues|Prepare a recurring-incident analysis linked to ticket references.
50|Implement corrective actions for recurring problems|Record root causes, approved corrective actions and implementation evidence.
20|Provide user guidance and review incident recurrence|Keep user guidance records and the follow-up recurrence assessment.''')

area('Business Application Support',5,'Customer','Support critical business applications and deliver approved application improvements with user acceptance.')
metric('Maintain availability and support of business applications',60,99.5,'Percent (%)','Monthly','Higher is better','Measure monthly availability of the agreed critical business application services using scheduled service minutes. Keep application incident records and agreed service exclusions.', '''30|Monitor critical application services and scheduled processes|Record application checks, scheduled-job results and detected failures.
50|Investigate application incidents and coordinate resolution|Keep incident references, investigation notes and vendor or developer actions.
20|Verify service restoration and maintain support records|Attach restoration checks and update application support records.''')
metric('Deliver approved application improvements',40,1,'count','On completion','Higher is better','Complete 1 approved application improvement package during 2027. Define its requirements, scope and acceptance criteria before work begins. Count the package only after documented user acceptance and operational handover.', '''30|Gather user requirements and confirm the improvement scope|Retain approved requirements, scope, acceptance criteria and responsible users.
40|Develop or coordinate approved application changes|Maintain change approvals, implementation records and technical test results.
30|Complete user testing, deployment and handover|Attach user acceptance, deployment approval and operational handover evidence.''')

area('Backup and Disaster Recovery',5,'Internal Processes','Maintain reliable backups and verify that agreed systems and data can be restored when required.')
metric('Complete scheduled backups and resolve backup failures',60,98,'Percent (%)','Monthly','Higher is better','Measure successful scheduled backup jobs / scheduled backup jobs due in the month x 100. Use the approved backup schedule and define how retries are counted so failed attempts are not hidden.', '''40|Monitor scheduled backups and investigate failed jobs|Keep backup-job reports, failure alerts and investigation references.
40|Resolve backup failures and verify successful completion|Record corrective actions and successful backup verification results.
20|Review backup retention and storage capacity|Maintain retention checks, storage-capacity reviews and required actions.''')
metric('Verify recovery readiness through restoration testing',40,4,'count','Annually','Higher is better','Complete 4 documented restoration or recovery tests during 2027. Count a test only when the approved scope is executed, results are recorded and the recovery owner accepts the result. Track failed tests and unresolved gaps.', '''20|Prepare the restoration test scope and recovery checklist|Keep approved test scopes, systems selected and recovery checklists.
50|Perform approved restoration and recovery tests|Record test dates, recovery steps, outcomes and measured recovery times.
30|Record results, resolve gaps and update recovery procedures|Maintain accepted test reports, corrective actions and revised procedures.''')

area('IT Asset and Vendor Management',3,'Financial','Maintain accurate IT asset and licence records and manage vendor obligations and renewal dates.')
metric('Maintain accurate IT asset and licence records',50,100,'Percent (%)','Quarterly','Higher is better','Measure verified and current records / asset and licence records in the agreed quarterly verification scope x 100. Record discrepancies, reconciliation actions and evidence of correction.', '''40|Verify assigned IT equipment and update the asset register|Record physical or approved remote verification and asset-register updates.
40|Review software licences, warranties and renewal dates|Maintain licence entitlements, warranty evidence and renewal schedules.
20|Record asset transfers, replacements and disposals|Retain approved transfer, replacement and disposal records.''')
metric('Complete vendor service reviews and renewals on time',50,100,'Percent (%)','Monthly','Higher is better','Measure vendor review and renewal actions completed by their due dates / actions due in the month x 100. Agree the due-action list and retain service reviews and renewal approvals.', '''40|Review vendor service performance and outstanding issues|Maintain SLA review records, service issues and agreed vendor actions.
40|Coordinate required contract, warranty and service renewals|Keep renewal requests, approvals and confirmed service coverage dates.
20|Maintain vendor review records and escalate service gaps|Update vendor review files and record unresolved-gap escalations.''')

area('IT Team Development and Documentation',2,'Learning and Growth','Develop technical capability and maintain approved operating procedures and current technical documentation.')
metric('Complete planned technical training and knowledge sharing',50,4,'count','Annually','Higher is better','Complete 4 approved team technical learning sessions during 2027. Count a session when attendance and learning or practical outcomes are recorded. Internal knowledge-sharing sessions may count when included in the approved plan; do not double-count the same event.', '''20|Identify technical skill gaps and prepare a training plan|Keep the skill-gap review, approved learning priorities and training plan.
50|Complete approved technical training or practical sessions|Record attendance, practical exercises or course completion evidence.
30|Conduct internal IT knowledge-sharing sessions|Keep session materials, attendance and the agreed learning outcomes.''')
metric('Maintain current IT operating procedures and technical records',50,100,'Percent (%)','Quarterly','Higher is better','Measure documents reviewed, updated where necessary and approved / documents due for review in the quarter x 100. Use a controlled register with document owners, versions and review dates.', '''20|Identify missing or outdated IT procedures and records|Maintain the document gap list, owners and required review dates.
50|Update operating procedures, configuration records and diagrams|Record updated document versions and the changes made.
30|Review documentation and publish approved versions|Retain review approvals and publish the controlled approved versions.''')

assert sum(a['weight'] for a in areas) == 100
assert len(areas) == 10 and sum(len(a['kpis']) for a in areas) == 20
assert sum(len(k['activities']) for a in areas for k in a['kpis']) == 60
assert all(sum(k['weight'] for k in a['kpis']) == 100 for a in areas)
(ROOT/'network-plan-entry-data.json').write_text(json.dumps(areas, indent=2),encoding='utf-8')

doc=Document(); sec=doc.sections[0]
sec.page_width=Inches(8.5);sec.page_height=Inches(11)
sec.top_margin=sec.bottom_margin=Inches(.55)
sec.left_margin=sec.right_margin=Inches(.6)
for style in doc.styles:
    if style.type == 1:
        for e in list(style.element.xpath('./w:pPr/w:pBdr')):
            e.getparent().remove(e)
normal=doc.styles['Normal'];normal.font.name='Calibri';normal.font.size=Pt(10)
normal.paragraph_format.space_after=Pt(4);normal.paragraph_format.line_spacing=1.03
for name,size in [('Title',24),('Heading 1',17),('Heading 2',12),('Heading 3',10.5)]:
    st=doc.styles[name];st.font.name='Calibri';st.font.size=Pt(size)
    st.font.color.rgb=RGBColor.from_string('000000')
    st.paragraph_format.space_before=Pt(7);st.paragraph_format.space_after=Pt(5)
    st.paragraph_format.keep_with_next=True
doc.core_properties.title='IT Network Performance Plan Entry Guide'
doc.core_properties.author='CPSTL'

def p(text,style=None): return doc.add_paragraph(text,style)
def h(text,level=1): return doc.add_heading(text,level)
def table(rows,widths,header=False):
    t=doc.add_table(rows=0,cols=len(widths));t.autofit=False
    borders=OxmlElement('w:tblBorders')
    for edge in ['top','left','bottom','right','insideH','insideV']:
        el=OxmlElement('w:'+edge);el.set(qn('w:val'),'single');el.set(qn('w:sz'),'4');el.set(qn('w:color'),'D9D9D9');borders.append(el)
    t._tbl.tblPr.append(borders)
    for c,w in zip(t.columns,widths): c.width=Inches(w)
    for i,values in enumerate(rows):
        r=t.add_row();r._tr.get_or_add_trPr().append(OxmlElement('w:cantSplit'))
        if header and i==0:r._tr.get_or_add_trPr().append(OxmlElement('w:tblHeader'))
        for j,value in enumerate(values):
            c=r.cells[j];c.width=Inches(widths[j]);pa=c.paragraphs[0]
            c.vertical_alignment=1
            pa.paragraph_format.space_before=Pt(2);pa.paragraph_format.space_after=Pt(2)
            pa.paragraph_format.line_spacing=1
            run=pa.add_run(str(value));run.font.size=Pt(9.5)
            if (header and i==0) or (not header and j==0):
                run.bold=True;shade=OxmlElement('w:shd');shade.set(qn('w:fill'),'EDF2F7');c._tc.get_or_add_tcPr().append(shade)
    p('')
    return t

p('IT Network Performance Plan Entry Guide','Title')
p('Complete copy and paste values for the 2027 Information Systems plan')
p('Use this guide to fill the existing draft plan. It contains 10 KPAs, 20 KPIs and 60 activities, with network-related KPAs carrying 60 percent of the personal plan. Targets and dates below are proposed planning values and require HOF agreement before publication.')
h('Plan fields')
table([('Field','Value to use'),('Plan title','2027 IT Infrastructure, Network and Service Delivery Plan'),('Department','Information Systems'),('Plan owner','EPF 14673 - G A S GAJADEERA'),('Performance year','2027'),('Plan ID','Keep the current permanent Plan ID for the existing draft.')],[1.55,5.5],True)
h('How to copy values')
for text in [
 'Copy only the value cell into the corresponding form field. Select the stated department and dropdown labels exactly.',
 'Weight fields use plain numbers: enter 25 for 25 percent, not 0.25. A Percent target of 99.5 is entered as 99.5.',
 'Keep existing system-generated KPA, KPI and activity IDs. Do not paste the page numbers or create a second plan for the same owner and year.',
 'Your first KPA currently has one KPI weighted 70. Add its second KPI weighted 30, then add the three activities under each KPI.',
 'For each following KPA, enter its KPA fields once. The second page repeats the KPA context only to keep copying easy.',
 'Due dates are proposed final annual delivery dates. Recurring checks and reports still follow their stated monitoring frequency; the annual date does not postpone monthly work.',
 'Monthly KPI Results currently covers Monthly KPIs. Quarterly, Annually and On completion values define the plan review cadence; agree how those outcome reviews will be recorded.',
 'If a measurement has no applicable denominator, record that fact in review evidence and follow the agreed reporting rule. Do not invent a result.'
]: p(text,'List Bullet')
h('Evidence and score meaning')
p('Activity completion requires approved evidence. KPI outcome achievement is measured separately using the target and measurement notes. Activity notes below specify the records that should support completion.')

doc.add_page_break();h('KPA allocation overview')
table([('KPA','Weight','Scorecard category')]+[(a['title'],a['weight'],a['category']) for a in areas],[3.9,.6,2.55],True)
h('Allocation checks')
for text in ['All KPA weights total 100 percent.','The two KPI weights within every KPA total 100 percent.','The three activity weights within every KPI total 100 percent.','KPAs 1 to 3 form the network focus and total 60 percent.','Example: 25 percent KPA x 70 percent KPI x 40 percent activity = 7 percent of the personal plan.']:
    p(text,'List Bullet')
p('The following 20 pages match the fields in the plan editor. Each page contains one KPI and its three supporting activities. Save the draft after completing a KPA, then resolve all publication-readiness messages before publishing.')

for i,a in enumerate(areas,1):
    for j,k in enumerate(a['kpis'],1):
        doc.add_page_break()
        h(f'KPA {i:02d} KPI {j:02d}')
        table([('KPA title',a['title']),('Department','Information Systems'),('Balanced scorecard category',a['category']),('Weight in personal plan',a['weight']),('KPA notes',a['notes'])],[1.65,5.4])
        h('KPI fields',2)
        table([('KPI title',k['title']),('Weight within KPA',k['weight']),('Target value',k['target']),('Target unit',k['unit']),('Monitoring frequency',k['frequency']),('Target direction',k['direction']),('KPI notes',k['notes'])],[1.65,5.4])
        h('Supporting activities',2)
        table([('Activity description','Weight within KPI','Due date','Activity notes')]+[(x['title'],x['weight'],x['due'],x['notes']) for x in k['activities']],[2.3,.62,.88,3.25],True)
        p('Check before saving: KPI weights in this KPA = 100. Activity weights in this KPI = 100.')

out=ROOT/'2027 IT Network Plan Copy Paste Guide.docx'
doc.save(out)
print(out)
print('Validated: 10 KPAs, 20 KPIs, 60 activities; every allocation totals 100.')
