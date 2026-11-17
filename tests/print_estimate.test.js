const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const modulePath = require('node:path').join(__dirname, '../public/js/print-estimate.js');
test('estimador de papel está disponível', () => assert.ok(fs.existsSync(modulePath), 'Implementar estimador de folhas'));
if (fs.existsSync(modulePath)) {
    const estimate = require(modulePath);
    test('conta folhas físicas por cópia antes de multiplicar', () => {
        assert.equal(estimate({pages:4,copies:30,sides:'two-sided-long-edge',numberUp:1}).sheets, 60);
        assert.equal(estimate({pages:3,copies:3,sides:'two-sided-long-edge',numberUp:1}).sheets, 6);
        assert.equal(estimate({pages:9,copies:2,sides:'two-sided-short-edge',numberUp:2}).sheets, 6);
        assert.equal(estimate({pages:4,copies:30,sides:'one-sided',numberUp:1}).sheets, 120);
    });
    test('intervalos sobrepostos contam cada página uma vez', () => {
        assert.equal(estimate({pages:10,copies:2,range:'1,3-5,4-6',numberUp:2}).selectedPages, 5);
        assert.equal(estimate({pages:10,copies:2,range:'1,3-5,4-6',numberUp:2}).sheets, 6);
    });
    test('não inventa estimativa com páginas desconhecidas ou opções inválidas', () => {
        for (const options of [{pages:0},{pages:4,range:'9'},{pages:4,range:'3-1'},{pages:4,range:'x'},{pages:4,range:'1,'},{pages:4,copies:0},{pages:4,copies:1.5}]) {
            assert.equal(estimate(options).sheets, null);
        }
    });
}
