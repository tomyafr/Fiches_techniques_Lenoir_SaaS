
content = open(r'c:\Users\tomdu\Desktop\saas_lenoir_fiches_techniques\api\rapport_final.php', 'r', encoding='utf-8').read()

# Fix 1: ensureImagesBase64
old_block1 = """                    // Prepend origin if relative
                    let absoluteUrl = src;
                    if (src.startsWith('/') && !src.startsWith('//')) {
                        absoluteUrl = window.location.origin + src;
                    }"""

new_block1 = """                    // Prepend origin if relative
                    let absoluteUrl = src;
                    if (!src.startsWith('http') && !src.startsWith('data:')) {
                        if (src.startsWith('/')) {
                            absoluteUrl = window.location.origin + src;
                        } else {
                            absoluteUrl = window.location.origin + '/' + src;
                        }
                    }"""

# Fix 2: machine image loop (which is currently empty or broken)
# We need to find where it was.
# Based on the last view_file:
# 1728:                             p.querySelectorAll('img').forEach(img => {
# 1729:                                 }
# 1730:                             });

broken_img_loop = """                            p.querySelectorAll('img').forEach(img => {
                                }
                            });"""

fixed_img_loop = """                            p.querySelectorAll('img').forEach(img => {
                                const src = img.getAttribute('src');
                                if (!src || src.startsWith('data:')) return;
                                let absoluteUrl = src;
                                if (!src.startsWith('http')) {
                                    if (src.startsWith('/')) {
                                        absoluteUrl = window.location.origin + src;
                                    } else {
                                        absoluteUrl = window.location.origin + '/' + src;
                                    }
                                }
                                img.src = absoluteUrl;
                            });"""

# Fix 3: Restore the deleted textarea loop header if it was deleted
deleted_textarea_header = """                                const specialKeys = ['aprf_attraction_comment'"""
restored_textarea_header = """                            p.querySelectorAll('textarea').forEach(ta => {
                                let val = ta.value || ta.innerHTML;
                                const specialKeys = ['aprf_attraction_comment'"""

# Apply replacements
if old_block1 in content:
    content = content.replace(old_block1, new_block1, 1)

if broken_img_loop in content:
    content = content.replace(broken_img_loop, fixed_img_loop, 1)

if deleted_textarea_header in content and 'p.querySelectorAll(\'textarea\')' not in content:
    content = content.replace(deleted_textarea_header, restored_textarea_header, 1)

with open(r'c:\Users\tomdu\Desktop\saas_lenoir_fiches_techniques\api\rapport_final.php', 'w', encoding='utf-8') as f:
    f.write(content)
print("Fix applied successfully")
