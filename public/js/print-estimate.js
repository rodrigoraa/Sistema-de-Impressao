(function (root) {
    'use strict';
    // Physical paper is an estimate, independent of the server's usage accounting.
    function estimate(options = {}) {
        const pages = Number(options.pages);
        const copies = Number(options.copies ?? 1);
        const numberUp = Number(options.numberUp ?? 1);
        const unknown = { sheets: null, selectedPages: null, printedSides: null };
        if (!Number.isSafeInteger(pages) || pages < 1 || !Number.isSafeInteger(copies) || copies < 1
            || ![1, 2, 4, 8].includes(numberUp)) return unknown;
        const range = String(options.range || '').replace(/\s/g, '');
        let selectedPages = pages;
        if (range) {
            const intervals = [];
            for (const part of range.split(',')) {
                if (!/^\d+(-\d+)?$/.test(part)) return unknown;
                const [start, last] = part.split('-').map(Number);
                const end = last ?? start;
                if (!Number.isSafeInteger(start) || !Number.isSafeInteger(end) || start < 1 || end < start || end > pages) return unknown;
                intervals.push([start, end]);
            }
            intervals.sort((a, b) => a[0] - b[0]);
            selectedPages = 0;
            let previousEnd = 0;
            for (const [start, end] of intervals) {
                selectedPages += Math.max(0, end - Math.max(start - 1, previousEnd));
                previousEnd = Math.max(previousEnd, end);
            }
        }
        const sidesPerCopy = Math.ceil(selectedPages / numberUp);
        const duplex = ['two-sided-long-edge', 'two-sided-short-edge'].includes(options.sides);
        const sheets = Math.ceil(sidesPerCopy / (duplex ? 2 : 1)) * copies;
        if (!Number.isSafeInteger(sheets)) return unknown;
        return { sheets, selectedPages, printedSides: sidesPerCopy * copies };
    }
    root.estimatePrintPaper = estimate;
    if (typeof module !== 'undefined') module.exports = estimate;
}(typeof window === 'undefined' ? globalThis : window));
