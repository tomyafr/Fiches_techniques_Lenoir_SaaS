from PIL import Image
import os

img_path = r'c:\Users\tomdu\Desktop\saas_lenoir_fiches_techniques\assets\machines\levage_diagram.png'
if os.path.exists(img_path):
    with Image.open(img_path) as img:
        print(f"Dimensions: {img.size}")
else:
    print("File not found.")
