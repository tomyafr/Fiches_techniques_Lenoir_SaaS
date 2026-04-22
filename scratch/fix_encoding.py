import os

def fix_encoding(filepath):
    try:
        with open(filepath, 'rb') as f:
            content = f.read()
        
        original_content = content
        
        # 1. Remove UTF-8 BOM
        if content.startswith(b'\xef\xbb\xbf'):
            content = content[3:]
            print(f"Removed BOM from {filepath}")
        
        # 2. Fix double-encoded UTF-8 characters (common in French)
        # These are sequences where UTF-8 bytes were interpreted as ISO-8859-1 and then re-encoded as UTF-8
        replacements = {
            b'\xc3\x83\xc2\xa9': b'\xc3\xa9', # é
            b'\xc3\x83\xc2\xa8': b'\xc3\xa8', # è
            b'\xc3\x83\xc2\xaa': b'\xc3\xaa', # ê
            b'\xc3\x83\xc2\xab': b'\xc3\xab', # ë
            b'\xc3\x83\xc2\xa0': b'\xc3\xa0', # à
            b'\xc3\x83\xc2\xa2': b'\xc3\xa2', # â
            b'\xc3\x83\xc2\xae': b'\xc3\xae', # î
            b'\xc3\x83\xc2\xaf': b'\xc3\xaf', # ï
            b'\xc3\x83\xc2\xb4': b'\xc3\xb4', # ô
            b'\xc3\x83\xc2\xbb': b'\xc3\xbb', # û
            b'\xc3\x83\xc2\xb9': b'\xc3\xb9', # ù
            b'\xc3\x83\xc2\xa7': b'\xc3\xa7', # ç
            b'\xc3\x83\xc2\x89': b'\xc3\x89', # É
            b'\xc3\x83\xc2\x80': b'\xc3\x80', # À
            b'\xc3\x83\xc2\xb1': b'\xc3\xb1', # ñ
            b'\xc3\x83\xe2\x82\xac': b'\xe2\x82\xac', # € (special case)
            b'\xc3\x82\xc2\xb0': b'\xc2\xb0', # °
            b'\xc3\x82\xc2\xbb': b'\xc2\xbb', # »
            b'\xc3\x82\xc2\xab': b'\xc2\xab', # «
            b'\xc3\x82\xc2\xa0': b'\xc2\xa0', # non-breaking space
            b'\xc3\xa2\xe2\x82\xac\xe2\x84\xa2': b'\x27', # ' (curly to straight or fixed)
            b'\xc3\x83\xc2\xaf': b'\xc3\xaf', # ï
        }
        
        for old, new in replacements.items():
            content = content.replace(old, new)
            
        # 3. Specific fix for "Ã " followed by space or other (sometimes "à" is just C3 80 interpreted as "Ã")
        # But be careful not to break valid Ã
        content = content.replace(b'\xc3\x83\x20', b'\xc3\xa0\x20') # "Ã " -> "à "
        
        # 4. Remove closing PHP tag at end of files to prevent "headers already sent" by trailing whitespace
        content_str = content.decode('utf-8', errors='ignore')
        if content_str.strip().endswith('?>'):
            # Find the last ?>
            last_pos = content_str.rfind('?>')
            # Check if there's anything but whitespace after it
            if not content_str[last_pos+2:].strip():
                content_str = content_str[:last_pos]
                content = content_str.encode('utf-8')
                print(f"Removed closing PHP tag from {filepath}")

        if content != original_content:
            with open(filepath, 'wb') as f:
                f.write(content)
            print(f"Cleaned and fixed encoding for {filepath}")
            
    except Exception as e:
        print(f"Error processing {filepath}: {e}")

# Process all PHP files in the workspace
workspace_root = r'c:\Users\tomdu\Desktop\saas_lenoir_fiches_techniques'
for root, dirs, files in os.walk(workspace_root):
    if '.git' in root or '.vercel' in root:
        continue
    for file in files:
        if file.endswith('.php'):
            fix_encoding(os.path.join(root, file))
