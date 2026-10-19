// Uji paritas parser level kelas di bundle (__jjParseLevel) dengan tabel yang sama seperti uji PHP.
// Kode parser diambil dari script patch (SNIPPET_PARSER) — yang persis disisipkan ke bundle.
// Pemakaian (dari root deploy): node docs/patches/uji-jenjang-sekolah-parser.mjs
import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';

const require = createRequire(import.meta.url);
const { SNIPPET_PARSER } = require('./apply-jenjang-sekolah-bundle.js');
const kasus = JSON.parse(readFileSync(new URL('./uji-jenjang-sekolah-kasus.json', import.meta.url), 'utf8'));

// eslint-disable-next-line no-new-func
const { parse, daftar } = new Function(SNIPPET_PARSER + '\nreturn { parse: __jjParseLevel, daftar: __jjDaftar };')();

let gagal = 0;
for (const [teks, jenjang, harap] of kasus.parse) {
  const hasil = parse(teks, daftar[jenjang]);
  if (hasil !== harap) {
    gagal++;
    console.log(`GAGAL parse ${JSON.stringify(teks)} (${jenjang}): dapat ${JSON.stringify(hasil)}, harap ${JSON.stringify(harap)}`);
  }
}
console.log(`${gagal ? 'GAGAL ' + gagal : 'LULUS'} dari ${kasus.parse.length} kasus parser`);
process.exit(gagal ? 1 : 0);
