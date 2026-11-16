from pathlib import Path
import pypdfium2 as pdfium
from PIL import Image, ImageOps, ImageDraw
root=Path(__file__).resolve().parent/'cio-doc-qa'
pdf=pdfium.PdfDocument(str(root/'word-layout.pdf'))
images=[]
for i in range(len(pdf)):
    page=pdf[i]
    text=page.get_textpage().get_text_range()
    im=page.render(scale=1.35).to_pil().convert('RGB')
    im.save(root/f'page-{i+1}.png')
    images.append(im)
    print(i+1,len(text),text[:90].replace('\r',' ').replace('\n',' '))
for start in range(0,len(images),2):
    parts=[]
    for i in range(start,min(len(images),start+2)):
        pic=ImageOps.expand(images[i],border=(8,24,8,8),fill='#dce2e9')
        ImageDraw.Draw(pic).text((12,5),f'PAGE {i+1}',fill='black')
        parts.append(pic)
    result=Image.new('RGB',(sum(x.width for x in parts),max(x.height for x in parts)),'#dce2e9')
    x=0
    for pic in parts: result.paste(pic,(x,0));x+=pic.width
    result.save(root/f'review-{start//2+1}.png')
print('PAGES',len(pdf))
