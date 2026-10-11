const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.join(__dirname, '..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');

test('all shipped CSV exports use the shared spreadsheet-safe writer', () => {
  const phpFiles = [
    'includes/class-dhc-csv.php',
    'includes/class-dhc-admin-pages.php',
    'includes/modules/class-dhc-form-capture.php',
  ];
  const direct = phpFiles.flatMap((file) => {
    const source = read(file);
    return [...source.matchAll(/fputcsv\s*\(/g)].map(() => file);
  });
  assert.deepEqual(direct, ['includes/class-dhc-csv.php']);
  assert.match(read('includes/class-dhc-admin-pages.php'), /DHC_CSV::write_row\(\$out/);
  assert.match(read('includes/modules/class-dhc-form-capture.php'), /DHC_CSV::write_row\( \$out/);
});

test('external workflow actions are pinned to reviewed full commit SHAs', () => {
  const workflows = [
    '.github/workflows/connector-security.yml',
    '.github/workflows/release.yml',
  ];
  const expected = new Map([
    ['actions/checkout', '11d5960a326750d5838078e36cf38b85af677262'],
    ['actions/setup-node', '49933ea5288caeca8642d1e84afbd3f7d6820020'],
    ['shivammathur/setup-php', 'eb7c497e18156a6bbabfef1d3a82760b9eda3962'],
    ['actions/upload-artifact', 'ea165f8d65b6e75b540449e92b4886f43607fa02'],
  ]);

  for (const workflow of workflows) {
    const source = read(workflow);
    for (const match of source.matchAll(/uses:\s*([^\s@]+)@([^\s#]+)/g)) {
      assert.match(match[2], /^[0-9a-f]{40}$/, `${workflow}: ${match[1]} must use a full SHA`);
      assert.equal(match[2], expected.get(match[1]), `${workflow}: ${match[1]} must use the reviewed SHA`);
    }
  }
});

test('runtime and documentation surfaces describe the bounded local form inbox', () => {
  const privacy = read('includes/class-dhc-privacy.php');
  const admin = read('includes/class-dhc-admin.php');
  const module = read('includes/modules/class-dhc-form-capture.php');
  const docs = read('README.md');

  assert.doesNotMatch(privacy, /NOT stored locally|does not store personal data locally/i);
  assert.match(privacy, /newest 250 clean and spam submissions/);
  assert.match(admin, /bounded local review inbox/);
  assert.match(module, /bounded local review inbox/);
  assert.doesNotMatch(docs, /does not store form data locally|does not store personal data locally/i);
});
