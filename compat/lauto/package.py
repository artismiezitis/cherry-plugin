"""Build verified delivery ZIPs for the existing LAUTO installation; never copy its config/database."""
from pathlib import Path
import argparse,hashlib,json,subprocess,zipfile

parser=argparse.ArgumentParser()
parser.add_argument('--site',required=True,help='Existing, patched WordPress root')
parser.add_argument('--output',required=True,help='Directory in which to write ZIPs')
args=parser.parse_args()
repo=Path(__file__).resolve().parents[2]
site=Path(args.site).resolve()
output=Path(args.output).resolve()
output.mkdir(parents=True,exist_ok=True)
manifest=json.loads((Path(__file__).parent/'manifest.json').read_text(encoding='utf-8'))
for item in manifest['files']:
    path=site/item['path']
    if hashlib.sha256(path.read_bytes()).hexdigest()!=item['after_sha256']:
        raise RuntimeError('Site patch differs from verified manifest: '+item['path'])
tracked=subprocess.check_output(['git','ls-files','-z'],cwd=repo).decode().split('\0')
runtime=[p for p in tracked if p and (p=='cherry-plugin.php' or p.startswith(('admin/','includes/','languages/','lib/')) or p in ['README.md','CHANGES.md']) and (repo/p).is_file()]
plugin=output/'cherry-plugin-1.2.9.zip'
with zipfile.ZipFile(plugin,'w',zipfile.ZIP_DEFLATED) as archive:
    for name in runtime:archive.write(repo/name,'cherry-plugin/'+name)
instructions=(Path(__file__).parent/'DELIVERY.txt').read_text(encoding='utf-8')
bundle=output/'lauto-php8-update.zip'
with zipfile.ZipFile(bundle,'w',zipfile.ZIP_DEFLATED) as archive:
    for name in runtime:archive.write(repo/name,'wp-content/plugins/cherry-plugin/'+name)
    for item in manifest['files']:archive.write(site/item['path'],item['path'])
    archive.writestr('DELIVERY.txt',instructions)
    archive.writestr('compatibility-manifest.json',json.dumps(manifest,indent=2))
for path in [plugin,bundle]:
    with zipfile.ZipFile(path) as archive:
        if archive.testzip() is not None:raise RuntimeError('Invalid ZIP: '+str(path))
        forbidden=['wp-config.php','database-before.sql','admin-cookies-private.json','admin-session-private.json']
        if any(Path(name).name in forbidden for name in archive.namelist()):raise RuntimeError('Private data in ZIP')
    print(path,hashlib.sha256(path.read_bytes()).hexdigest())
