const Conv = (() => {
  const GREEK = ['alpha','beta','gamma','delta','zeta','eta','theta','iota','kappa','lambda','mu','nu','xi','rho','sigma','tau','upsilon','chi','psi','omega',
    'Gamma','Delta','Theta','Lambda','Xi','Pi','Sigma','Upsilon','Phi','Psi','Omega'];
  const SYM = {};
  GREEK.forEach(g => { SYM[g] = '\\' + g; });
  Object.assign(SYM, {
    pi: '\\pi', epsilon: '\\varepsilon', eps: '\\varepsilon', phi: '\\varphi', omicron: 'o',
    inf: '\\infty', infty: '\\infty', infinity: '\\infty', oo: '\\infty', deg: '^{\\circ}',
    nabla: '\\nabla', partial: '\\partial', hbar: '\\hbar', ell: '\\ell', emptyset: '\\emptyset',
    forall: '\\forall', exists: '\\exists',
    RR: '\\mathbb{R}', NN: '\\mathbb{N}', ZZ: '\\mathbb{Z}', QQ: '\\mathbb{Q}', CC: '\\mathbb{C}'
  });
  const op = n => '\\operatorname{' + n + '}';
  const FUNCS = {
    sin: '\\sin', cos: '\\cos', tan: '\\tan', cot: '\\cot', sec: '\\sec', csc: '\\csc',
    arcsin: '\\arcsin', arccos: '\\arccos', arctan: '\\arctan', sinh: '\\sinh', cosh: '\\cosh', tanh: '\\tanh', coth: '\\coth',
    tg: op('tg'), ctg: op('ctg'), arctg: op('arctg'), arcctg: op('arcctg'),
    ln: '\\ln', log: '\\log', lg: '\\lg', exp: '\\exp', max: '\\max', min: '\\min', det: '\\det',
    gcd: '\\gcd', arg: '\\arg', dim: '\\dim', ker: '\\ker', sgn: op('sgn'), lcm: op('lcm'),
    NWD: op('NWD'), NWW: op('NWW')
  };
  const SPECIAL = new Set(['angle','cases','underbrace','overbrace','overset','underset','boxed','sqrt','root','cbrt','abs','norm','floor','ceil','frac','binom','sum','prod','int','iint','oint','lim',
    'diff','pdiff','vec','hat','bar','dot','ddot','tilde','mat','bmat']);
  const WORD_OPS = new Set(['in', 'mod']);
  const SPLIT = new Set([...Object.keys(SYM), ...Object.keys(FUNCS), ...WORD_OPS]);
  const REL = { '=': '=', '<': '<', '>': '>', '<=': '\\le', '>=': '\\ge', '!=': '\\neq', '<>': '\\neq', '=/=': '\\neq',
    '~=': '\\approx', '->': '\\to', '=>': '\\Rightarrow', '<=>': '\\Leftrightarrow', 'in': '\\in' };
  const OPS = ['<=>','=/=','...','<=','>=','!=','<>','~=','->','=>','+-','-+','**','==',
    '+','-','*','/','^','_','=','<','>','!',"'",',',';','(',')','[',']','{','}','|','%'];
  const UNI = { '≤':'<=','≥':'>=','≠':'!=','≈':'~=','±':'+-','∓':'-+','×':'*','·':'*','⋅':'*','÷':'/','−':'-','–':'-',
    '→':'->','⇒':'=>','⇔':'<=>','∞':'inf','√':' sqrt ','²':'^2','³':'^3','°':'deg','∑':' sum ','∫':' int ','∂':'partial',
    '∈':' in ','…':'...','„':'"','”':'"','“':'"' };
  const GREEK_UNI = 'αβγδεζηθικλμνξοπρστυφχψω';
  const GREEK_UNI_NAMES = ['alpha','beta','gamma','delta','epsilon','zeta','eta','theta','iota','kappa','lambda','mu','nu','xi','omicron','pi','rho','sigma','sigma','tau','upsilon','phi','chi','psi','omega'];
  const GREEK_UNI_CAP = { 'Γ':'Gamma','Δ':'Delta','Θ':'Theta','Λ':'Lambda','Ξ':'Xi','Π':'Pi','Σ':'Sigma','Φ':'Phi','Ψ':'Psi','Ω':'Omega' };

  const fail = (msg, pos) => Object.assign(new Error(msg), { pos });

  function normalize(s) {
    let r = '';
    for (const ch of s) {
      const gi = GREEK_UNI.indexOf(ch);
      if (gi >= 0) r += GREEK_UNI_NAMES[gi];
      else if (GREEK_UNI_CAP[ch]) r += GREEK_UNI_CAP[ch];
      else if (UNI[ch]) r += UNI[ch];
      else r += ch;
    }
    return r;
  }

  function splitWord(w) {
    if (SPLIT.has(w) || SPECIAL.has(w)) return [w];
    const out = [];
    let i = 0;
    while (i < w.length) {
      let m = null;
      for (let len = Math.min(w.length - i, 8); len >= 2; len--) {
        const s = w.substr(i, len);
        if (SPLIT.has(s)) { m = s; break; }
      }
      if (!m) m = w[i];
      out.push(m);
      i += m.length;
    }
    return out;
  }

  function tokenize(src) {
    const s = normalize(src);
    const toks = [];
    let i = 0;
    while (i < s.length) {
      const c = s[i];
      if (/\s/.test(c)) { i++; continue; }
      const rest = s.slice(i);
      const num = /^(\d+(\.\d+)?|\.\d+)/.exec(rest);
      if (num) { toks.push({ type: 'num', v: num[0], pos: i }); i += num[0].length; continue; }
      const word = /^[A-Za-z]+/.exec(rest);
      if (word) {
        let pos = i;
        for (const p of splitWord(word[0])) {
          toks.push(WORD_OPS.has(p) ? { type: 'op', v: p, pos } : { type: 'id', v: p, pos });
          pos += p.length;
        }
        i += word[0].length;
        const d = /^\d+/.exec(s.slice(i));
        const last = toks[toks.length - 1];
        if (d && last.type === 'id' && !FUNCS[last.v] && !SPECIAL.has(last.v)) { last.sub = d[0]; i += d[0].length; }
        continue;
      }
      // `...` — wstawka LaTeX przepisywana bez zmian
      if (c === '`') {
        const j = s.indexOf('`', i + 1);
        if (j < 0) throw fail('Brakuje zamykającego znaku ` wstawki LaTeX', i);
        toks.push({ type: 'rawtex', v: s.slice(i + 1, j), pos: i });
        i = j + 1;
        continue;
      }
      if (c === '"') {
        const j = s.indexOf('"', i + 1);
        if (j < 0) throw fail('Brakuje zamykającego cudzysłowu', i);
        toks.push({ type: 'str', v: s.slice(i + 1, j), pos: i });
        i = j + 1;
        continue;
      }
      // "n!=6" to silnia, "a != b" (ze spacją przed !) to „różne od”
      if (c === '!' && s[i + 1] === '=' && i > 0 && !/\s/.test(s[i - 1])) {
        toks.push({ type: 'op', v: '!', pos: i }); i++; continue;
      }
      const o = OPS.find(x => s.startsWith(x, i));
      if (o) { toks.push({ type: 'op', v: o === '**' ? '^' : o === '==' ? '=' : o, pos: i }); i += o.length; continue; }
      throw fail(`Nieznany znak „${c}”`, i);
    }
    toks.push({ type: 'end', pos: s.length });
    return toks;
  }

  const CLOSE = { '(': ')', '[': ']', '{': '}' };

  class Parser {
    constructor(toks) { this.t = toks; this.i = 0; this.abs = 0; }
    peek() { return this.t[this.i]; }
    is(v) { const k = this.peek(); return k.type === 'op' && k.v === v; }
    eat(v) { if (this.is(v)) { this.i++; return true; } return false; }
    expect(v) {
      if (!this.eat(v)) {
        const k = this.peek();
        throw fail(k.type === 'end' ? `Brakuje „${v}” na końcu` : `Spodziewałem się „${v}”, a jest „${k.v}”`, k.pos);
      }
    }
    startsPrimary() {
      const k = this.peek();
      if (k.type === 'num' || k.type === 'id' || k.type === 'str' || k.type === 'rawtex') return true;
      if (k.type !== 'op') return false;
      if (k.v === '|') return this.abs === 0;
      return ['(', '[', '{', '...'].includes(k.v);
    }
    parseLine() {
      const e = this.parseList();
      const k = this.peek();
      if (k.type !== 'end') throw fail(`Nie rozumiem „${k.v}” w tym miejscu`, k.pos);
      return e;
    }
    parseList() {
      const items = [this.parseRel()];
      while (this.eat(',')) items.push(this.parseRel());
      return items.length > 1 ? { t: 'list', items } : items[0];
    }
    relOp() { const k = this.peek(); return k.type === 'op' && REL[k.v] ? k.v : null; }
    relStart() { const k = this.peek(); return k.type === 'op' && ['-', '+', '+-', '-+'].includes(k.v); }
    parseRel() {
      const parts = [this.relOp() ? null : this.parseAdd()];
      const ops = [];
      let o;
      while ((o = this.relOp())) { this.i++; ops.push(o); parts.push(this.startsPrimary() || this.relStart() ? this.parseAdd() : null); }
      return ops.length ? { t: 'rel', parts, ops } : parts[0];
    }
    parseAdd() {
      let l = this.parseMul();
      for (;;) {
        const k = this.peek();
        if (k.type === 'op' && ['+', '-', '+-', '-+'].includes(k.v)) { this.i++; l = { t: 'bin', op: k.v, l, r: this.parseMul() }; }
        else return l;
      }
    }
    parseMul() {
      let l = this.parseUnary();
      for (;;) {
        if (this.eat('*')) l = { t: 'bin', op: '*', l, r: this.parseUnary() };
        else if (this.eat('/')) l = { t: 'frac', n: l, d: this.parseUnary() };
        else if (this.eat('mod')) l = { t: 'bin', op: 'mod', l, r: this.parseUnary() };
        else if (this.startsPrimary()) l = { t: 'bin', op: 'imp', l, r: this.parsePower() };
        else return l;
      }
    }
    parseUnary() {
      const k = this.peek();
      if (k.type === 'op' && ['-', '+', '+-', '-+'].includes(k.v)) { this.i++; return { t: 'un', op: k.v, e: this.parseUnary() }; }
      return this.parsePower();
    }
    parsePower() {
      const b = this.parsePostfix();
      const exps = [];
      while (this.eat('^')) exps.push(this.parseScript());
      if (!exps.length) return b;
      let e = exps.pop();
      while (exps.length) e = { t: 'pow', b: exps.pop(), e };
      return { t: 'pow', b, e };
    }
    parsePostfix() {
      let e = this.parsePrimary();
      for (;;) {
        if (this.eat('!')) e = { t: 'fact', e };
        else if (this.eat("'")) e = { t: 'prime', e };
        else if (this.eat('%')) e = { t: 'pct', e };
        else if (this.eat('_')) e = { t: 'sub', b: e, s: this.parseScript() };
        else return e;
      }
    }
    parseScript() {
      const k = this.peek();
      if (k.type === 'op' && (k.v === '(' || k.v === '{')) {
        this.i++;
        const e = this.parseList();
        this.expect(CLOSE[k.v]);
        return e;
      }
      if (k.type === 'op' && (k.v === '-' || k.v === '+')) { this.i++; return { t: 'un', op: k.v, e: this.parseScript() }; }
      return this.parsePrimary();
    }
    parseArgs() {
      this.expect('(');
      const rows = [[]];
      if (this.eat(')')) return rows;
      for (;;) {
        rows[rows.length - 1].push(this.parseRel());
        if (this.eat(',')) continue;
        if (this.eat(';')) { rows.push([]); continue; }
        this.expect(')');
        return rows;
      }
    }
    flatArgs(name, allowed) {
      const a = this.parseArgs().flat();
      if (!allowed.includes(a.length)) {
        throw fail(`${name}(…) przyjmuje ${allowed.join(' lub ')} argument${allowed.every(n => n === 1) ? '' : 'y'}, a dostało ${a.length}`, this.peek().pos);
      }
      return a;
    }
    parsePrimary() {
      const k = this.peek();
      if (k.type === 'num') { this.i++; return { t: 'num', v: k.v }; }
      if (k.type === 'str') { this.i++; return { t: 'text', v: k.v }; }
      if (k.type === 'rawtex') { this.i++; return { t: 'rawtex', v: k.v }; }
      if (k.type === 'id') {
        this.i++;
        if (SPECIAL.has(k.v)) return this.parseSpecial(k.v);
        if (FUNCS[k.v]) return this.parseFunc(k.v);
        return { t: 'id', v: k.v, sub: k.sub };
      }
      if (k.type === 'op') {
        if (CLOSE[k.v]) {
          this.i++;
          const saved = this.abs; this.abs = 0;
          const e = this.parseList();
          this.abs = saved;
          this.expect(CLOSE[k.v]);
          return { t: 'grp', open: k.v, e };
        }
        if (k.v === '|') {
          this.i++; this.abs++;
          const e = this.parseRel();
          this.abs--;
          this.expect('|');
          return { t: 'delim', kind: 'abs', e };
        }
        if (k.v === '...') { this.i++; return { t: 'raw', v: '\\dots' }; }
      }
      if (k.type === 'end') throw fail('Wyrażenie urywa się — czegoś brakuje na końcu', k.pos);
      throw fail(`Nie spodziewałem się „${k.v}” w tym miejscu`, k.pos);
    }
    parseFunc(name) {
      let sub = null, sup = null, arg = null;
      if (this.eat('_')) sub = this.parseScript();
      if (this.eat('^')) sup = this.parseScript();
      if (this.is('(')) arg = this.parsePrimary();
      else if (this.startsPrimary()) arg = this.parsePower();
      return { t: 'fn', name, sub, sup, arg };
    }
    operand() { return this.is('(') ? this.parseArgs().flat()[0] : this.parsePower(); }
    parseSpecial(name) {
      switch (name) {
        case 'sqrt': {
          if (!this.is('(')) return { t: 'sqrt', e: this.parsePower() };
          const a = this.flatArgs('sqrt', [1, 2]);
          return { t: 'sqrt', e: a[0], n: a[1] };
        }
        case 'root': { const a = this.flatArgs('root', [2]); return { t: 'sqrt', n: a[0], e: a[1] }; }
        case 'cbrt': return { t: 'sqrt', n: { t: 'num', v: '3' }, e: this.operand() };
        case 'abs': case 'norm': case 'floor': case 'ceil':
          return { t: 'delim', kind: name, e: this.operand() };
        case 'angle': {
          const a = this.is('(') ? this.parseArgs().flat() : [this.parsePower()];
          return { t: 'delim', kind: 'angle', e: a.length > 1 ? { t: 'list', items: a } : a[0] };
        }
        case 'frac': case 'binom': { const a = this.flatArgs(name, [2]); return { t: name, n: a[0], d: a[1] }; }
        case 'vec': case 'hat': case 'bar': case 'dot': case 'ddot': case 'tilde':
          return { t: 'acc', kind: name, e: this.is('(') ? this.flatArgs(name, [1])[0] : this.parsePrimary() };
        case 'cases': {
          const rows = this.parseArgs();
          if (rows.some(r => r.length < 1 || r.length > 2)) throw fail('cases(…): każdy wiersz to wartość i opcjonalny warunek, wiersze oddziel średnikiem', this.peek().pos);
          return { t: 'cases', rows };
        }
        case 'underbrace': case 'overbrace': {
          const a = this.flatArgs(name, [1, 2]);
          return { t: 'brace', kind: name, e: a[0], label: a[1] || null };
        }
        case 'overset': case 'underset': { const a = this.flatArgs(name, [2]); return { t: 'stack', kind: name, top: a[0], base: a[1] }; }
        case 'boxed': return { t: 'boxed', e: this.operand() };
        case 'mat': case 'bmat': {
          const rows = this.parseArgs();
          const w = Math.max(...rows.map(r => r.length));
          if (rows.some(r => r.length !== w)) throw fail('Wiersze macierzy mają różną liczbę elementów', this.peek().pos);
          return { t: 'mat', env: name === 'mat' ? 'pmatrix' : 'bmatrix', rows };
        }
        case 'diff': case 'pdiff': {
          const a = this.flatArgs(name, [2, 3]);
          return { t: 'diff', partial: name === 'pdiff', f: a[0], x: a[1], n: a[2] };
        }
        default: return this.parseBig(name);
      }
    }
    parseBig(name) {
      const node = { t: 'big', op: name, sub: null, sup: null, body: null, dvar: null };
      if (this.is('(')) {
        const a = this.parseArgs().flat();
        const n = a.length;
        if (name === 'sum' || name === 'prod') {
          if (n === 1) [node.body] = a;
          else if (n === 2) [node.sub, node.body] = a;
          else if (n === 3) [node.sub, node.sup, node.body] = a;
          else throw fail(`${name}(…) przyjmuje 1–3 argumenty`, this.peek().pos);
        } else if (name === 'lim') {
          if (n === 1) [node.body] = a;
          else if (n === 2) [node.sub, node.body] = a;
          else throw fail('lim(…) przyjmuje 1 lub 2 argumenty', this.peek().pos);
        } else {
          if (n === 1) [node.body] = a;
          else if (n === 2) [node.body, node.dvar] = a;
          else if (n === 3) [node.sub, node.sup, node.body] = a;
          else if (n === 4) [node.sub, node.sup, node.body, node.dvar] = a;
          else throw fail(`${name}(…) przyjmuje 1–4 argumenty`, this.peek().pos);
        }
      } else {
        if (this.eat('_')) node.sub = this.parseScript();
        if (this.eat('^')) node.sup = this.parseScript();
        if (this.startsPrimary()) node.body = this.parseMul();
      }
      if (/int$/.test(name) && !node.dvar && node.body) {
        const b = node.body;
        if (b.t === 'bin' && b.op === 'imp' && b.r.t === 'id') {
          if (b.l.t === 'id' && b.l.v === 'd' && !b.l.sub) { node.dvar = b.r; node.body = null; }
          else if (b.l.t === 'bin' && b.l.op === 'imp' && b.l.r.t === 'id' && b.l.r.v === 'd' && !b.l.r.sub) { node.dvar = b.r; node.body = b.l.l; }
        }
      }
      return node;
    }
  }

  const TALL = /\\(frac|sum|prod|int|iint|oint|binom|begin|lim)/;
  const joinTex = (a, b) => (/\\[A-Za-z]+$/.test(a) && /^[A-Za-z0-9]/.test(b) ? a + ' ' + b : a + b);
  const BIG = { sum: '\\sum', prod: '\\prod', int: '\\int', iint: '\\iint', oint: '\\oint', lim: '\\lim' };
  const ACC = { vec: '\\vec', hat: '\\hat', bar: '\\overline', dot: '\\dot', ddot: '\\ddot', tilde: '\\tilde' };
  const DELIM = { angle: ['\\langle ', ' \\rangle'], abs: ['|', '|'], norm: ['\\|', '\\|'], floor: ['\\lfloor ', ' \\rfloor'], ceil: ['\\lceil ', ' \\rceil'] };

  function makeEmitter(opts) {
    const mul = { cdot: ' \\cdot ', times: ' \\times ' }[opts.mul];
    const paren = (s, l = '(', r = ')') => (TALL.test(s) ? `\\left${l}${s}\\right${r}` : `${l}${s}${r}`);
    const isSum = n => n && ((n.t === 'bin' && ['+', '-', '+-', '-+'].includes(n.op)) || n.t === 'rel');
    const strip = n => (n.t === 'grp' && (n.open === '(' || n.open === '{') ? E(n.e) : E(n));
    const simple = n => n.t === 'id' || (n.t === 'sub' && n.b.t === 'id');

    function fn(n, supOverride) {
      let s = FUNCS[n.name];
      if (n.sub) s += `_{${strip(n.sub)}}`;
      const sup = supOverride || n.sup;
      if (sup) s += `^{${strip(sup)}}`;
      if (n.arg) s = n.arg.t === 'grp' ? s + E(n.arg) : s + ' ' + E(n.arg);
      return s;
    }

    function E(n) {
      switch (n.t) {
        case 'num': return opts.comma ? n.v.replace('.', '{,}') : n.v;
        case 'id': { const b = SYM[n.v] ?? n.v; return n.sub ? `${b}_{${n.sub}}` : b; }
        case 'text': return `\\text{${n.v.replace(/([{}%$#&_\\])/g, '\\$1')}}`;
        case 'raw': return n.v;
        case 'rawtex': return n.v;
        case 'cases': return `\\begin{cases} ${n.rows.map(r => r.map(E).join(' & ')).join(' \\\\ ')} \\end{cases}`;
        case 'brace': return `\\${n.kind}{${strip(n.e)}}` + (n.label ? `${n.kind === 'underbrace' ? '_' : '^'}{${strip(n.label)}}` : '');
        case 'stack': return `\\${n.kind}{${strip(n.top)}}{${strip(n.base)}}`;
        case 'boxed': return `\\boxed{${strip(n.e)}}`;
        case 'list': return n.items.map(E).join(',\\ ');
        case 'rel': return relTex(n, false);
        case 'bin': {
          const l = E(n.l);
          if (n.op === 'imp') {
            const r = E(n.r);
            if (/^[0-9.]/.test(r) || (/^-/.test(r))) return `${l} \\cdot ${r}`;
            return joinTex(l, r);
          }
          const r = n.r.t === 'un' ? paren(E(n.r)) : E(n.r);
          if (n.op === '*') return mul ? l + mul + r : (/^[0-9.]/.test(r) ? `${l} \\cdot ${r}` : joinTex(l, r));
          if (n.op === 'mod') return `${l} \\bmod ${r}`;
          const o = { '+': '+', '-': '-', '+-': '\\pm', '-+': '\\mp' }[n.op];
          return `${l} ${o} ${r}`;
        }
        case 'un': {
          const e = isSum(n.e) ? paren(E(n.e)) : E(n.e);
          const o = { '-': '-', '+': '+', '+-': '\\pm', '-+': '\\mp' }[n.op];
          return joinTex(o, e);
        }
        case 'frac':
          if (n.n.t === 'un' && n.n.op === '-') return `-\\frac{${strip(n.n.e)}}{${strip(n.d)}}`;
          return `\\frac{${strip(n.n)}}{${strip(n.d)}}`;
        case 'binom': return `\\binom{${strip(n.n)}}{${strip(n.d)}}`;
        case 'pow': {
          if (n.b.t === 'fn' && !n.b.sup) return fn(n.b, n.e);
          const b = ['fact', 'frac', 'un', 'bin', 'pct', 'prime', 'pow'].includes(n.b.t) ? `{${E(n.b)}}` : E(n.b);
          return `${b}^{${strip(n.e)}}`;
        }
        case 'sub': return `${E(n.b)}_{${strip(n.s)}}`;
        case 'fact': return `${E(n.e)}!`;
        case 'prime': return `${E(n.e)}'`;
        case 'pct': return `${E(n.e)}\\%`;
        case 'grp': {
          const inner = E(n.e);
          if (n.open === '{') return `{${inner}}`;
          return n.open === '[' ? paren(inner, '[', ']') : paren(inner);
        }
        case 'delim': {
          const [l, r] = DELIM[n.kind];
          const inner = strip(n.e);
          return TALL.test(inner) ? `\\left${l.trim()} ${inner} \\right${r.trim()}` : `${l}${inner}${r}`;
        }
        case 'fn': return fn(n);
        case 'sqrt': return n.n ? `\\sqrt[${strip(n.n)}]{${strip(n.e)}}` : `\\sqrt{${strip(n.e)}}`;
        case 'acc': {
          const inner = strip(n.e);
          const cmd = n.kind === 'vec' && n.e.t !== 'id' ? '\\overrightarrow' : ACC[n.kind];
          return `${cmd}{${inner}}`;
        }
        case 'mat': return `\\begin{${n.env}} ${n.rows.map(r => r.map(E).join(' & ')).join(' \\\\ ')} \\end{${n.env}}`;
        case 'diff': {
          const d = n.partial ? '\\partial' : 'd';
          const x = E(n.x);
          const k = n.n ? strip(n.n) : null;
          const top = k ? `${d}^{${k}}` : d;
          const bot = k ? `${joinTex(d, x)}^{${k}}` : joinTex(d, x);
          if (simple(n.f)) return `\\frac{${joinTex(top, E(n.f))}}{${bot}}`;
          const f = isSum(n.f) ? paren(E(n.f)) : E(n.f);
          return `\\frac{${top}}{${bot}} ${f}`;
        }
        case 'big': {
          let s = BIG[n.op];
          if (n.sub) s += `_{${strip(n.sub)}}`;
          if (n.sup) s += `^{${strip(n.sup)}}`;
          if (n.body) s += ' ' + (isSum(n.body) ? paren(E(n.body)) : E(n.body));
          if (n.dvar) s += ` \\,d${E(n.dvar)}`;
          return s;
        }
      }
      throw new Error('Nieznany węzeł ' + n.t);
    }

    function relTex(n, align) {
      let s = n.parts[0] ? E(n.parts[0]) : '';
      n.ops.forEach((o, i) => {
        const amp = align && i === 0 ? '&' : '';
        s += (s ? ' ' : '') + amp + REL[o] + (n.parts[i + 1] ? ' ' + E(n.parts[i + 1]) : '');
      });
      return s;
    }

    return (node, align) => (node.t === 'rel' && align ? relTex(node, true) : E(node));
  }

  // Odczyt słowny po polsku — dla czytników ekranu
  const PL_GREEK = { alpha: 'alfa', beta: 'beta', gamma: 'gamma', delta: 'delta', epsilon: 'epsilon', eps: 'epsilon', zeta: 'dzeta', eta: 'eta',
    theta: 'theta', iota: 'jota', kappa: 'kappa', lambda: 'lambda', mu: 'mi', nu: 'ni', xi: 'ksi', omicron: 'omikron', pi: 'pi', rho: 'ro',
    sigma: 'sigma', tau: 'tau', upsilon: 'ypsilon', phi: 'fi', chi: 'chi', psi: 'psi', omega: 'omega' };
  const PL_SYM = { inf: 'nieskończoność', infty: 'nieskończoność', infinity: 'nieskończoność', oo: 'nieskończoność', deg: 'stopni',
    nabla: 'nabla', partial: 'delta cząstkowa', hbar: 'h kreślone', ell: 'l', emptyset: 'zbiór pusty', forall: 'dla każdego', exists: 'istnieje',
    RR: 'zbiór liczb rzeczywistych', NN: 'zbiór liczb naturalnych', ZZ: 'zbiór liczb całkowitych', QQ: 'zbiór liczb wymiernych', CC: 'zbiór liczb zespolonych' };
  const PL_REL = { '=': 'równa się', '<': 'mniejsze od', '>': 'większe od', '<=': 'mniejsze lub równe', '>=': 'większe lub równe',
    '!=': 'różne od', '<>': 'różne od', '=/=': 'różne od', '~=': 'w przybliżeniu równe', '->': 'dąży do', '=>': 'wynika',
    '<=>': 'wtedy i tylko wtedy, gdy', in: 'należy do' };
  const PL_FN = { sin: 'sinus', cos: 'cosinus', tan: 'tangens', tg: 'tangens', cot: 'cotangens', ctg: 'cotangens', sec: 'secans', csc: 'cosecans',
    arcsin: 'arcus sinus', arccos: 'arcus cosinus', arctan: 'arcus tangens', arctg: 'arcus tangens', arcctg: 'arcus cotangens',
    sinh: 'sinus hiperboliczny', cosh: 'cosinus hiperboliczny', tanh: 'tangens hiperboliczny', coth: 'cotangens hiperboliczny',
    ln: 'logarytm naturalny', log: 'logarytm', lg: 'logarytm dziesiętny', exp: 'eksponens', max: 'maksimum', min: 'minimum', det: 'wyznacznik',
    gcd: 'największy wspólny dzielnik', NWD: 'największy wspólny dzielnik', lcm: 'najmniejsza wspólna wielokrotność',
    NWW: 'najmniejsza wspólna wielokrotność', arg: 'argument', dim: 'wymiar', ker: 'jądro', sgn: 'signum' };
  const PL_BIN = { '+': 'plus', '-': 'minus', '+-': 'plus minus', '-+': 'minus plus', '*': 'razy', mod: 'modulo' };

  function makeSpeaker(opts) {
    const atomic = n => ['num', 'id', 'text', 'raw'].includes(n.t) || (n.t === 'sub' && atomic(n.b) && atomic(n.s));
    const unwrap = n => (n.t === 'grp' && (n.open === '(' || n.open === '{') ? n.e : n);
    const end = (n, word) => (atomic(unwrap(n)) ? '' : `, ${word}`);
    const powWord = e => {
      const u = unwrap(e);
      if (u.t === 'num' && u.v === '2') return 'do kwadratu';
      if (u.t === 'num' && u.v === '3') return 'do sześcianu';
      return `do potęgi ${S(u)}${end(e, 'koniec potęgi')}`;
    };
    function S(n) {
      switch (n.t) {
        case 'num': return n.v.replace('.', ' przecinek ');
        case 'id': {
          let b = PL_SYM[n.v] ?? PL_GREEK[n.v] ?? (PL_GREEK[n.v.toLowerCase()] ? 'wielka ' + PL_GREEK[n.v.toLowerCase()] : n.v);
          return n.sub ? `${b} indeks ${n.sub}` : b;
        }
        case 'text': return n.v.trim();
        case 'raw': return 'i tak dalej';
        case 'rawtex': return 'wstawka LaTeX';
        case 'cases': return 'układ przypadków: ' + n.rows.map(r => {
          if (!r[1]) return S(r[0]);
          const cond = S(r[1]);
          return `${S(r[0])}, ${/^(gdy|dla|jeśli|jeżeli|gdzie|o ile)\b/i.test(cond) ? '' : 'gdy '}${cond}`;
        }).join('; ') + ', koniec przypadków';
        case 'brace': return `${S(unwrap(n.e))}, z klamrą ${n.kind === 'underbrace' ? 'dolną' : 'górną'}${n.label ? ': ' + S(unwrap(n.label)) : ''}`;
        case 'stack': return `${S(unwrap(n.base))}, ${n.kind === 'underset' ? 'pod spodem' : 'nad nim'} ${S(unwrap(n.top))}`;
        case 'boxed': return `w ramce: ${S(unwrap(n.e))}`;
        case 'list': return n.items.map(S).join('; ');
        case 'rel': {
          let s = n.parts[0] ? S(n.parts[0]) : '';
          n.ops.forEach((o, i) => {
            let r = n.parts[i + 1] ? S(n.parts[i + 1]) : '';
            // „dąży do” i „należy do” wymagają dopełniacza
            if (o === '->' || o === 'in') r = r.replace(/^nieskończoność(?![a-ząćęłńóśźż])/, 'nieskończoności').replace(/^minus nieskończoność(?![a-ząćęłńóśźż])/, 'minus nieskończoności');
            s += (s ? ' ' : '') + PL_REL[o] + (r ? ' ' + r : '');
          });
          return s;
        }
        case 'bin': return n.op === 'imp' ? `${S(n.l)} ${S(n.r)}` : `${S(n.l)} ${PL_BIN[n.op]} ${S(n.r)}`;
        case 'un': return `${PL_BIN[n.op]} ${S(n.e)}`;
        case 'frac': {
          const a = unwrap(n.n), b = unwrap(n.d);
          if (atomic(a) && atomic(b)) return `${S(a)} przez ${S(b)}`;
          return `ułamek, licznik: ${S(a)}, mianownik: ${S(b)}, koniec ułamka`;
        }
        case 'binom': return `symbol Newtona ${S(unwrap(n.n))} po ${S(unwrap(n.d))}`;
        case 'pow':
          if (n.b.t === 'fn' && !n.b.sup) return fnS(n.b, n.e);
          return `${S(n.b)} ${powWord(n.e)}`;
        case 'sub': return `${S(n.b)} indeks ${S(unwrap(n.s))}`;
        case 'fact': return `${S(n.e)} silnia`;
        case 'prime': return `${S(n.e)} prim`;
        case 'pct': return `${S(n.e)} procent`;
        case 'grp': return n.open === '{' ? S(n.e) : `nawias, ${S(n.e)}, zamknięcie nawiasu`;
        case 'delim': {
          const w = { angle: ['nawias ostry,', 'koniec nawiasu ostrego'], abs: ['wartość bezwzględna z', 'koniec wartości bezwzględnej'], norm: ['norma z', 'koniec normy'],
            floor: ['część całkowita z', 'koniec części całkowitej'], ceil: ['sufit z', 'koniec sufitu'] }[n.kind];
          const e = unwrap(n.e);
          return atomic(e) ? `${w[0]} ${S(e)}` : `${w[0]} ${S(e)}, ${w[1]}`;
        }
        case 'fn': return fnS(n);
        case 'sqrt': {
          const e = unwrap(n.e);
          const head = n.n ? (unwrap(n.n).t === 'num' && unwrap(n.n).v === '3' ? 'pierwiastek sześcienny z' : `pierwiastek stopnia ${S(unwrap(n.n))} z`) : 'pierwiastek z';
          return `${head} ${S(e)}${end(n.e, 'koniec pierwiastka')}`;
        }
        case 'acc': {
          const e = S(unwrap(n.e));
          return { vec: `wektor ${e}`, hat: `${e} z daszkiem`, bar: `${e} z kreską`, dot: `${e} z kropką`, ddot: `${e} z dwiema kropkami`, tilde: `${e} z tyldą` }[n.kind];
        }
        case 'mat': return `macierz ${n.rows.length} na ${n.rows[0].length}: ` + n.rows.map((r, i) => `wiersz ${i + 1}: ${r.map(S).join(', ')}`).join('; ') + ', koniec macierzy';
        case 'diff': {
          const k = n.n ? ` rzędu ${S(unwrap(n.n))}` : '';
          return `pochodna${n.partial ? ' cząstkowa' : ''}${k}${n.f ? ' ' + S(unwrap(n.f)) : ''} po ${S(n.x)}`;
        }
        case 'big': {
          const name = { sum: 'suma', prod: 'iloczyn', int: 'całka', iint: 'całka podwójna', oint: 'całka krzywoliniowa', lim: 'granica' }[n.op];
          let s = name;
          if (n.op === 'lim') { if (n.sub) s += `, gdy ${S(unwrap(n.sub))},`; }
          else {
            if (n.sub) s += ` od ${S(unwrap(n.sub))}`;
            if (n.sup) s += ` do ${S(unwrap(n.sup)).replace(/^(minus )?nieskończoność(?![a-ząćęłńóśźż])/, '$1nieskończoności')}`;
          }
          if (n.body) s += ` z ${S(n.body)}`;
          if (n.dvar) s += `, d ${S(n.dvar)}`;
          return s;
        }
      }
      return '';
    }
    function fnS(n, supOverride) {
      let s = PL_FN[n.name] || n.name;
      if (n.sub) s += ` o podstawie ${S(unwrap(n.sub))}`;
      const sup = supOverride || n.sup;
      if (sup) {
        const u = unwrap(sup);
        s += u.t === 'un' && u.op === '-' && u.e.t === 'num' && u.e.v === '1' ? ' odwrotny' : ' ' + powWord(sup);
      }
      if (n.arg) {
        const a = unwrap(n.arg);
        s += ` z ${S(a)}${atomic(a) ? '' : ', koniec argumentu'}`;
      }
      return s;
    }
    return S;
  }

  // ===== W drugą stronę: LaTeX → zwykły zapis =====
  const L_FN = {};
  Object.entries(FUNCS).forEach(([k, v]) => { if (!v.startsWith('\\operatorname')) L_FN[v.slice(1)] = k; });
  const L_GREEK = {};
  GREEK.forEach(g => { L_GREEK[g] = g; });
  Object.assign(L_GREEK, { pi: 'pi', epsilon: 'epsilon', varepsilon: 'epsilon', phi: 'phi', varphi: 'phi', vartheta: 'theta', varpi: 'pi', varrho: 'rho', varsigma: 'sigma' });
  const L_SYM = { infty: 'inf', partial: 'partial', nabla: 'nabla', hbar: 'hbar', ell: 'ell', emptyset: 'emptyset', varnothing: 'emptyset',
    forall: 'forall', exists: 'exists', circ: 'deg' };
  const L_REL = { le: '<=', leq: '<=', leqslant: '<=', ge: '>=', geq: '>=', geqslant: '>=', ne: '!=', neq: '!=', approx: '~=', sim: '~=',
    simeq: '~=', to: '->', rightarrow: '->', longrightarrow: '->', Rightarrow: '=>', implies: '=>', Longrightarrow: '=>',
    Leftrightarrow: '<=>', iff: '<=>', Longleftrightarrow: '<=>', in: 'in', equiv: '=' };
  const L_SPACE = new Set([',', ';', ':', '!', ' ', 'quad', 'qquad', 'displaystyle', 'textstyle', 'limits', 'nolimits', 'nonumber', 'notag']);
  const L_ACC = { vec: 'vec', overrightarrow: 'vec', hat: 'hat', widehat: 'hat', bar: 'bar', overline: 'bar', dot: 'dot', ddot: 'ddot', tilde: 'tilde', widetilde: 'tilde' };
  const L_BIG = { sum: 'sum', prod: 'prod', int: 'int', iint: 'iint', oint: 'oint', lim: 'lim' };
  const L_BB = { R: 'RR', N: 'NN', Z: 'ZZ', Q: 'QQ', C: 'CC' };
  const L_PRIMARY = new Set([...Object.keys(L_FN), ...Object.keys(L_GREEK), ...Object.keys(L_SYM), ...Object.keys(L_ACC), ...Object.keys(L_BIG),
    'frac', 'dfrac', 'tfrac', 'cfrac', 'sqrt', 'binom', 'dbinom', 'tbinom', 'left', 'begin', 'text', 'textrm', 'mbox', 'mathrm', 'operatorname',
    'mathbb', 'mathbf', 'mathit', 'boldsymbol', 'mathcal', 'dots', 'ldots', 'cdots', 'vdots', 'lfloor', 'lceil', '{', '|',
    ...GREEK.filter(g => /^[A-Z]/.test(g))]);
  const L_OPEN = { '(': ')', '[': ']' };
  const L_NOT_PRIMARY = new Set(['pm', 'mp', 'cdot', 'times', 'div', 'bmod', 'mod', 'right', 'end', '\\', '&', 'rfloor', 'rceil', 'rangle', 'rvert', 'rVert', '}', 'over', 'choose']);

  function ltok(s) {
    const toks = [];
    let i = 0;
    while (i < s.length) {
      const c = s[i];
      if (/\s/.test(c)) { i++; continue; }
      if (c === '\\') {
        const m = /^\\([A-Za-z]+)/.exec(s.slice(i));
        if (m) { toks.push({ type: 'cmd', v: m[1], pos: i }); i += m[0].length; continue; }
        if (i + 1 >= s.length) throw fail('Samotny ukośnik \\ na końcu', i);
        toks.push({ type: 'cmd', v: s[i + 1], pos: i }); i += 2; continue;
      }
      const num = /^(\d+(\.\d+|\{,\}\d+)?|\.\d+)/.exec(s.slice(i));
      if (num) { toks.push({ type: 'num', v: num[0].replace('{,}', '.'), pos: i }); i += num[0].length; continue; }
      if (/[A-Za-z]/.test(c)) { toks.push({ type: 'id', v: c, pos: i }); i++; continue; }
      if (c === '%') { i = s.indexOf('\n', i); if (i < 0) break; continue; }
      toks.push({ type: 'ch', v: c, pos: i }); i++;
    }
    toks.push({ type: 'end', pos: s.length });
    return toks;
  }

  class LParser {
    constructor(toks, src) { this.t = toks; this.i = 0; this.abs = 0; this.src = src; this.warnings = []; }
    peek() {
      for (;;) {
        const k = this.t[this.i];
        if (k.type === 'cmd' && L_SPACE.has(k.v)) { this.i++; continue; }
        return k;
      }
    }
    next() { const k = this.peek(); this.i++; return k; }
    isCh(v) { const k = this.peek(); return k.type === 'ch' && k.v === v; }
    isCmd(v) { const k = this.peek(); return k.type === 'cmd' && k.v === v; }
    eatCh(v) { if (this.isCh(v)) { this.i++; return true; } return false; }
    eatCmd(v) { if (this.isCmd(v)) { this.i++; return true; } return false; }
    expectCh(v) {
      if (!this.eatCh(v)) {
        const k = this.peek();
        throw fail(k.type === 'end' ? `Brakuje „${v}” na końcu` : `Spodziewałem się „${v}”, a jest „${k.type === 'cmd' ? '\\' + k.v : k.v}”`, k.pos);
      }
    }
    show(k) { return k.type === 'cmd' ? '\\' + k.v : k.v; }
    rawBrace() {
      this.expectCh('{');
      const start = this.t[this.i].pos;
      let depth = 1;
      while (depth) {
        const k = this.t[this.i++];
        if (k.type === 'end') throw fail('Brakuje „}”', k.pos);
        if (k.type === 'ch' && k.v === '{') depth++;
        if (k.type === 'ch' && k.v === '}') depth--;
        if (!depth) return this.src.slice(start, k.pos);
      }
    }
    relStart() { return this.addOp() !== null; }
    relOp() {
      const k = this.peek();
      if (k.type === 'ch' && ['=', '<', '>'].includes(k.v)) return k.v;
      if (k.type === 'cmd' && L_REL[k.v]) return L_REL[k.v];
      return null;
    }
    startsPrimary() {
      const k = this.peek();
      if (k.type === 'num' || k.type === 'id') return true;
      if (k.type === 'ch') return k.v === '(' || k.v === '[' || k.v === '{' || (k.v === '|' && this.abs === 0);
      // Każde polecenie, które nie jest działaniem, relacją ani zamknięciem, może zaczynać czynnik (także nieznane)
      if (k.type === 'cmd') return !L_NOT_PRIMARY.has(k.v) && !L_REL[k.v] && !(k.v === '|' && this.abs > 0);
      return false;
    }
    parseLine() {
      const e = this.parseList();
      const k = this.peek();
      if (k.type !== 'end') throw fail(`Nie rozumiem „${this.show(k)}” w tym miejscu`, k.pos);
      return e;
    }
    parseList() {
      const items = [this.parseRel()];
      while (this.eatCh(',')) items.push(this.parseRel());
      return items.length > 1 ? { t: 'list', items } : items[0];
    }
    parseRel() {
      const parts = [this.relOp() ? null : this.parseAdd()];
      const ops = [];
      let o;
      while ((o = this.relOp())) { this.i++; ops.push(o); parts.push(this.startsPrimary() || this.relStart() ? this.parseAdd() : null); }
      return ops.length ? { t: 'rel', parts, ops } : parts[0];
    }
    addOp() {
      const k = this.peek();
      if (k.type === 'ch' && (k.v === '+' || k.v === '-')) return k.v;
      if (k.type === 'cmd' && (k.v === 'pm' || k.v === 'mp')) return k.v === 'pm' ? '+-' : '-+';
      return null;
    }
    parseAdd() {
      let l = this.parseMul();
      let o;
      while ((o = this.addOp())) { this.i++; l = { t: 'bin', op: o, l, r: this.parseMul() }; }
      return l;
    }
    parseMul() {
      let l = this.parseUnary();
      for (;;) {
        if (this.eatCmd('cdot') || this.eatCmd('times') || this.eatCh('*')) l = { t: 'bin', op: '*', l, r: this.parseUnary() };
        else if (this.eatCh('/') || this.eatCmd('div')) l = { t: 'frac', n: l, d: this.parseUnary() };
        else if (this.eatCmd('bmod') || this.eatCmd('mod')) l = { t: 'bin', op: 'mod', l, r: this.parseUnary() };
        else if (this.startsPrimary()) {
          const r = this.parsePower();
          if (l.t === 'diff' && !l.f) l = { ...l, f: r };
          else l = { t: 'bin', op: 'imp', l, r };
        }
        else return l;
      }
    }
    parseUnary() {
      const o = this.addOp();
      if (o) { this.i++; return { t: 'un', op: o, e: this.parseUnary() }; }
      return this.parsePower();
    }
    scripts() {
      let sub = null, sup = null;
      for (;;) {
        if (this.eatCh('_')) sub = this.parseScript();
        else if (this.eatCh('^')) sup = this.parseScript();
        else return { sub, sup };
      }
    }
    parsePower() {
      let e = this.parsePrimary();
      for (;;) {
        if (this.eatCh('!')) { e = { t: 'fact', e }; continue; }
        if (this.eatCh("'")) { e = { t: 'prime', e }; continue; }
        if (this.eatCmd('%')) { e = { t: 'pct', e }; continue; }
        if (this.isCh('_') || this.isCh('^')) {
          const { sub, sup } = this.scripts();
          if (sub) e = { t: 'sub', b: e, s: sub };
          if (sup) e = sup.t === 'id' && sup.v === 'deg' ? { t: 'bin', op: 'imp', l: e, r: sup } : { t: 'pow', b: e, e: sup };
          continue;
        }
        return e;
      }
    }
    parseScript() {
      const k = this.peek();
      if (k.type === 'ch' && k.v === '{') { this.i++; const e = this.isCh('}') ? { t: 'raw', v: '' } : this.parseList(); this.expectCh('}'); return e; }
      if (k.type === 'num' && k.v.length > 1) {
        // x^23 w LaTeX to x² i 3
        const first = { t: 'num', v: k.v[0] };
        k.v = k.v.slice(1); k.pos += 1;
        return first;
      }
      if (k.type === 'ch' && (k.v === '-' || k.v === '+')) { this.i++; return { t: 'un', op: k.v, e: this.parseScript() }; }
      return this.parsePrimary();
    }
    arg() {
      const k = this.peek();
      if (k.type === 'ch' && k.v === '{') return this.parseScript();
      if (k.type === 'num' && k.v.length > 1) return this.parseScript();
      return this.parsePrimary();
    }
    until(closeCh) {
      const saved = this.abs; this.abs = 0;
      const e = this.parseList();
      this.abs = saved;
      this.expectCh(closeCh);
      return e;
    }
    parsePrimary() {
      const k = this.next();
      if (k.type === 'num') return { t: 'num', v: k.v };
      if (k.type === 'id') return { t: 'id', v: k.v };
      if (k.type === 'ch') {
        if (L_OPEN[k.v]) return { t: 'grp', open: k.v, e: this.until(L_OPEN[k.v]) };
        if (k.v === '{') return { t: 'grp', open: '{', e: this.until('}') };
        if (k.v === '|') { this.abs++; const e = this.parseRel(); this.abs--; this.expectCh('|'); return { t: 'delim', kind: 'abs', e }; }
      }
      if (k.type === 'end') throw fail('Wyrażenie urywa się — czegoś brakuje na końcu', k.pos);
      if (k.type !== 'cmd') {
        // Pojedynczy nietypowy znak (np. ? w \stackrel{?}{=}) przepisujemy bez zmian
        if (k.type === 'ch' && !'}])&'.includes(k.v)) { this.warnings.push(`Znak „${k.v}” przepisano bez zmian.`); return { t: 'rawtex', v: k.v }; }
        throw fail(`Nie spodziewałem się „${k.v}” w tym miejscu`, k.pos);
      }
      const c = k.v;
      if (L_GREEK[c]) return { t: 'id', v: L_GREEK[c] };
      if (L_SYM[c]) return { t: 'id', v: L_SYM[c] };
      if (L_FN[c]) return this.parseFn(L_FN[c]);
      if (L_ACC[c]) return { t: 'acc', kind: L_ACC[c], e: this.arg() };
      if (L_BIG[c]) return this.parseBig(L_BIG[c]);
      switch (c) {
        case 'frac': case 'dfrac': case 'tfrac': case 'cfrac': return this.fracOrDiff(this.arg(), this.arg());
        case 'binom': case 'dbinom': case 'tbinom': return { t: 'binom', n: this.arg(), d: this.arg() };
        case 'sqrt': {
          let n = null;
          if (this.eatCh('[')) n = this.until(']');
          return { t: 'sqrt', n, e: this.arg() };
        }
        case 'text': case 'textrm': case 'mbox': return { t: 'text', v: this.rawBrace() };
        case 'mathrm': case 'operatorname': {
          const name = this.rawBrace().trim();
          if (FUNCS[name]) return this.parseFn(name);
          if (/^[A-Za-z]$/.test(name)) return { t: 'id', v: name };
          return { t: 'text', v: name };
        }
        case 'mathbb': { const n = this.rawBrace().trim(); return L_BB[n] ? { t: 'id', v: L_BB[n] } : { t: 'id', v: n }; }
        case 'mathbf': case 'mathit': case 'boldsymbol': case 'mathcal': return this.arg();
        case 'dots': case 'ldots': case 'cdots': case 'vdots': return { t: 'raw', v: '\\dots' };
        case 'lfloor': return { t: 'delim', kind: 'floor', e: this.untilCmd('rfloor') };
        case 'lceil': return { t: 'delim', kind: 'ceil', e: this.untilCmd('rceil') };
        case '|': { this.abs++; const e = this.parseRel(); this.abs--; if (!this.eatCmd('|')) throw fail('Brakuje zamykającego \\|', this.peek().pos); return { t: 'delim', kind: 'norm', e }; }
        case '{': return { t: 'grp', open: '(', e: this.untilCmd('}') };
        case 'left': return this.parseLeft();
        case 'begin': return this.parseEnv(k);
        case 'underbrace': case 'overbrace': {
          const e = this.arg();
          let label = null;
          if (this.eatCh(c === 'underbrace' ? '_' : '^')) label = this.parseScript();
          return { t: 'brace', kind: c, e, label };
        }
        case 'overset': case 'stackrel': { const top = this.arg(); return { t: 'stack', kind: 'overset', top, base: this.arg() }; }
        case 'underset': { const top = this.arg(); return { t: 'stack', kind: 'underset', top, base: this.arg() }; }
        case 'boxed': return { t: 'boxed', e: this.arg() };
        case 'color': { this.rawBrace(); return this.startsPrimary() ? this.parsePower() : { t: 'raw', v: '' }; }
        case 'textcolor': case 'colorbox': { this.rawBrace(); return this.arg(); }
        case 'textbf': case 'textit': case 'textnormal': case 'emph': case 'textsf': case 'texttt': return { t: 'text', v: this.rawBrace() };
        case 'bm': case 'mathsf': case 'mathtt': case 'mathfrak': case 'mathscr': case 'pmb': return this.arg();
        case 'langle': { const e = this.untilCmd('rangle'); return { t: 'delim', kind: 'angle', e }; }
        case 'lvert': { const e = this.untilCmd('rvert'); return { t: 'delim', kind: 'abs', e }; }
        case 'lVert': { const e = this.untilCmd('rVert'); return { t: 'delim', kind: 'norm', e }; }
      }
      return this.rawCommand(k);
    }
    /** Nieznane polecenie: przepisujemy je razem z argumentami bez zmian i zostawiamy uwagę. */
    rawCommand(k) {
      const skipGroup = (open, close) => {
        let depth = 0;
        do {
          const t = this.t[this.i++];
          if (t.type === 'end') throw fail(`Brakuje „${close}” po \\${k.v}`, t.pos);
          if (t.type === 'ch' && t.v === open) depth++;
          if (t.type === 'ch' && t.v === close) depth--;
        } while (depth > 0);
      };
      if (this.t[this.i].type === 'ch' && this.t[this.i].v === '[') skipGroup('[', ']');
      for (let n = 0; n < 3 && this.t[this.i].type === 'ch' && this.t[this.i].v === '{'; n++) skipGroup('{', '}');
      const v = this.src.slice(k.pos, this.t[this.i].pos).trim();
      this.warnings.push(`Polecenie \\${k.v} przepisano bez zmian jako wstawkę LaTeX.`);
      return { t: 'rawtex', v };
    }
    untilCmd(v) {
      const saved = this.abs; this.abs = 0;
      const e = this.parseList();
      this.abs = saved;
      if (!this.eatCmd(v)) throw fail(`Brakuje \\${v}`, this.peek().pos);
      return e;
    }
    parseLeft() {
      const d = this.next();
      const key = d.type === 'cmd' ? '\\' + d.v : d.v;
      const saved = this.abs; this.abs = 0;
      const e = this.parseList();
      this.abs = saved;
      if (!this.eatCmd('right')) throw fail('Brakuje \\right do pary z \\left', this.peek().pos);
      this.next();
      switch (key) {
        case '(': return { t: 'grp', open: '(', e };
        case '[': return { t: 'grp', open: '[', e };
        case '|': case '\\vert': return { t: 'delim', kind: 'abs', e };
        case '\\|': case '\\Vert': return { t: 'delim', kind: 'norm', e };
        case '\\lfloor': return { t: 'delim', kind: 'floor', e };
        case '\\lceil': return { t: 'delim', kind: 'ceil', e };
        case '\\langle': return { t: 'delim', kind: 'angle', e };
        default: return { t: 'grp', open: key === '.' ? '{' : '(', e };
      }
    }
    parseEnv(beginTok) {
      const env = this.rawBrace().trim();
      const kinds = { pmatrix: 'pmatrix', bmatrix: 'bmatrix', matrix: 'pmatrix', vmatrix: 'pmatrix', Bmatrix: 'pmatrix', array: 'pmatrix',
        cases: 'cases', dcases: 'cases', rcases: 'cases' };
      if (!kinds[env]) return this.rawEnv(beginTok, env);
      if (env === 'array' && this.isCh('{')) this.rawBrace(); // specyfikacja kolumn, np. {cc}
      const rows = [[]];
      for (;;) {
        if (this.isCmd('end')) { this.i++; this.rawBrace(); break; }
        if (this.isCmd('\\') || this.isCh('&')) { rows[rows.length - 1].push({ t: 'raw', v: '' }); }
        else {
          const cell = [this.parseRel()];
          while (this.eatCh(',')) {
            if (this.isCh('&') || this.isCmd('\\') || this.isCmd('end')) break; // „x^2, & x>0” — przecinek tylko ozdobny
            cell.push(this.parseRel());
          }
          rows[rows.length - 1].push(cell.length > 1 ? { t: 'list', items: cell } : cell[0]);
        }
        if (this.eatCh('&')) continue;
        if (this.eatCmd('\\')) { if (!this.isCmd('end')) rows.push([]); continue; }
        if (!this.isCmd('end')) throw fail('W macierzy spodziewałem się & albo \\\\', this.peek().pos);
      }
      if (kinds[env] === 'cases') return { t: 'cases', rows: rows.filter(r => r.length && !(r.length === 1 && r[0].t === 'raw')).map(r => r.slice(0, 2)) };
      return { t: 'mat', env: kinds[env] === 'bmatrix' ? 'bmatrix' : 'pmatrix', rows };
    }
    /** Nieznane środowisko: przepisujemy całość od \\begin do pasującego \\end bez zmian. */
    rawEnv(beginTok, env) {
      let depth = 1;
      while (depth > 0) {
        const t = this.t[this.i++];
        if (t.type === 'end') throw fail(`Brakuje \\end{${env}}`, t.pos);
        if (t.type === 'cmd' && t.v === 'begin') depth++;
        if (t.type === 'cmd' && t.v === 'end') { depth--; if (depth === 0) this.rawBrace(); }
      }
      this.warnings.push(`Środowisko ${env} przepisano bez zmian jako wstawkę LaTeX.`);
      return { t: 'rawtex', v: this.src.slice(beginTok.pos, this.t[this.i].pos).trim() };
    }
    parseFn(name) {
      const { sub, sup } = this.scripts();
      let arg = null;
      if (this.isCh('(') || (this.isCmd('left'))) arg = this.parsePrimary();
      else if (this.startsPrimary()) arg = this.parsePower();
      return { t: 'fn', name, sub, sup, arg };
    }
    parseBig(op) {
      const { sub, sup } = this.scripts();
      const node = { t: 'big', op, sub, sup, body: null, dvar: null };
      if (this.startsPrimary()) node.body = this.parseMul();
      if (/int$/.test(op) && node.body) {
        const b = node.body;
        if (b.t === 'bin' && b.op === 'imp' && b.r.t === 'id') {
          if (b.l.t === 'id' && b.l.v === 'd') { node.dvar = b.r; node.body = null; }
          else if (b.l.t === 'bin' && b.l.op === 'imp' && b.l.r.t === 'id' && b.l.r.v === 'd') { node.dvar = b.r; node.body = b.l.l; }
        }
      }
      return node;
    }
    fracOrDiff(n, d) {
      // \frac{d}{dx}, \frac{df}{dx}, \frac{\partial f}{\partial y}, \frac{d^2 f}{dx^2}, \frac{\partial^2}{\partial y^2}
      const un = x => (x.t === 'grp' && x.open === '{' ? x.e : x);
      const N = un(n), D = un(d);
      const isD = x => x.t === 'id' && (x.v === 'd' || x.v === 'partial');
      // operator d albo d^k → [rodzaj, rząd]
      const op = x => (isD(x) ? [x.v, null] : x.t === 'pow' && isD(x.b) ? [x.b.v, x.e] : null);
      if (D.t === 'bin' && D.op === 'imp' && isD(D.l)) {
        let v = D.r, order = null;
        if (v.t === 'pow') { order = v.e; v = v.b; }
        if (v.t === 'id' || v.t === 'sub') {
          const top = op(N);
          if (top) return { t: 'diff', partial: top[0] === 'partial', f: null, x: v, n: order || top[1] };
          if (N.t === 'bin' && N.op === 'imp' && op(N.l)) {
            const t2 = op(N.l);
            return { t: 'diff', partial: t2[0] === 'partial', f: N.r, x: v, n: order || t2[1] };
          }
        }
      }
      return { t: 'frac', n, d };
    }
  }

  // Zwykły zapis z drzewa
  function makePlain() {
    const ATOM = new Set(['num', 'id', 'text', 'raw', 'grp', 'delim', 'sqrt', 'acc', 'mat', 'binom', 'fact', 'prime', 'pct', 'diff', 'rawtex', 'cases', 'brace', 'stack', 'boxed']);
    const un = n => (n.t === 'grp' && n.open === '{' ? n.e : n);
    const atomic = n => { n = un(n); return ATOM.has(n.t) || (n.t === 'fn' && n.arg && n.arg.t === 'grp') || (n.t === 'sub' && atomic(n.b)) || (n.t === 'pow' && atomic(n.b)); };
    const wrap = n => (atomic(n) ? P(un(n)) : `(${P(un(n))})`);
    const script = n => {
      const u = un(n);
      const s = P(u);
      return (u.t === 'num' || u.t === 'id') && !/\s/.test(s) ? s : `(${s})`;
    };
    function P(n) {
      if (!n) return '';
      switch (n.t) {
        case 'num': return n.v;
        case 'id': return n.v;
        case 'text': return `"${n.v}"`;
        case 'raw': return n.v === '\\dots' ? '...' : '';
        case 'rawtex': return '`' + n.v + '`';
        case 'cases': return `cases(${n.rows.map(r => r.map(c => P(un(c))).join(', ')).join('; ')})`;
        case 'brace': return `${n.kind}(${P(un(n.e))}${n.label ? ', ' + P(un(n.label)) : ''})`;
        case 'stack': return `${n.kind}(${P(un(n.top))}, ${P(un(n.base))})`;
        case 'boxed': return `boxed(${P(un(n.e))})`;
        case 'list': return n.items.map(P).join(', ');
        case 'rel': {
          let s = n.parts[0] ? P(n.parts[0]) : '';
          n.ops.forEach((o, i) => { s += (s ? ' ' : '') + o + (n.parts[i + 1] ? ' ' + P(n.parts[i + 1]) : ''); });
          return s;
        }
        case 'bin': {
          if (n.op === 'imp') {
            const l = P(n.l);
            const r = n.r.t === 'frac' ? `(${P(n.r)})` : P(n.r);
            if (/\d$/.test(l) && /^\d/.test(r)) return `${l} * ${r}`;
            if (n.r.t === 'id' && n.r.v === 'deg') return l + 'deg';
            if (n.l.t === 'frac') return `${l} ${r}`;
            if (/^[(\[|]/.test(r)) return l + r;
            // jednoliterowe czynniki sklejamy: 4ac, mc^2, 2a_n — ale nie po słowach typu alpha, pi
            if (/[A-Za-z0-9)\]]$/.test(l) && !/[A-Za-z]{2,}$/.test(l) && /^[A-Za-z](?![A-Za-z])/.test(r)) return l + r;
            return `${l} ${r}`;
          }
          const sym = { '+': '+', '-': '-', '+-': '+-', '-+': '-+', '*': '*', mod: 'mod' }[n.op];
          return `${P(n.l)} ${sym} ${P(n.r)}`;
        }
        case 'un': return { '-': '-', '+': '+', '+-': '+-', '-+': '-+' }[n.op] + P(n.e);
        case 'frac': return `${wrap(n.n)}/${wrap(n.d)}`;
        case 'binom': return `binom(${P(un(n.n))}, ${P(un(n.d))})`;
        case 'pow':
          if (n.b.t === 'fn' && !n.b.sup) return fnP(n.b, n.e);
          return `${atomic(n.b) && n.b.t !== 'pow' ? P(un(n.b)) : `(${P(un(n.b))})`}^${script(n.e)}`;
        case 'sub': return `${P(n.b)}_${script(n.s)}`;
        case 'fact': return `${wrap(n.e)}!`;
        case 'prime': return `${P(n.e)}'`;
        case 'pct': return `${P(n.e)}%`;
        case 'grp': return n.open === '{' ? P(n.e) : n.open === '[' ? `[${P(n.e)}]` : `(${P(n.e)})`;
        case 'delim': return n.kind === 'abs' ? `|${P(un(n.e))}|` : `${n.kind}(${P(un(n.e))})`;
        case 'fn': return fnP(n);
        case 'sqrt': return n.n ? `root(${P(un(n.n))}, ${P(un(n.e))})` : `sqrt(${P(un(n.e))})`;
        case 'acc': return `${n.kind}(${P(un(n.e))})`;
        case 'mat': return `${n.env === 'bmatrix' ? 'bmat' : 'mat'}(${n.rows.map(r => r.map(P).join(', ')).join('; ')})`;
        case 'diff': {
          const name = n.partial ? 'pdiff' : 'diff';
          const order = n.n ? `, ${P(un(n.n))}` : '';
          return `${name}(${n.f ? P(un(n.f)) : '?'}, ${P(n.x)}${order})`;
        }
        case 'big': {
          const a = [];
          if (n.sub) a.push(P(un(n.sub)));
          if (n.sup) a.push(P(un(n.sup)));
          if (n.body) a.push(P(un(n.body)));
          if (n.dvar) a.push(P(n.dvar));
          if (n.op === 'lim' && !n.body) return `lim_${script(n.sub)}`;
          return `${n.op}(${a.join(', ')})`;
        }
      }
      return '';
    }
    function fnP(n, supOverride) {
      let s = n.name;
      if (n.sub) s += '_' + script(n.sub);
      const sup = supOverride || n.sup;
      if (sup) s += '^' + script(sup);
      if (n.arg) s += n.arg.t === 'grp' ? P(n.arg) : ' ' + P(n.arg);
      return s;
    }
    return P;
  }

  function splitTop(body) {
    // dzieli po \\ i usuwa & — ale nie wewnątrz macierzy
    const lines = [];
    let depth = 0, cur = '', i = 0;
    while (i < body.length) {
      if (body.startsWith('\\begin', i)) depth++;
      if (body.startsWith('\\end', i)) depth--;
      if (depth === 0 && body.startsWith('\\\\', i)) { lines.push(cur); cur = ''; i += 2; continue; }
      if (depth === 0 && body[i] === '&') { i++; continue; }
      cur += body[i]; i++;
    }
    lines.push(cur);
    return lines.filter(l => l.trim());
  }

  function fromLatex(src) {
    let s = src.trim()
      .replace(/\\(label|tag)\{[^}]*\}/g, '')
      .replace(/\\(nonumber|notag)/g, '');
    const strip = [/^\$\$([\s\S]*)\$\$$/, /^\$([\s\S]*)\$$/, /^\\\[([\s\S]*)\\\]$/, /^\\\(([\s\S]*)\\\)$/,
      /^\\begin\{(?:equation|displaymath|align|gather|multline)\*?\}([\s\S]*)\\end\{(?:equation|displaymath|align|gather|multline)\*?\}$/,
      /^\\begin\{(?:aligned|gathered|split|alignedat)\}(?:\{\d+\})?([\s\S]*)\\end\{(?:aligned|gathered|split|alignedat)\}$/];
    for (let changed = true; changed;) {
      changed = false;
      for (const re of strip) { const m = re.exec(s); if (m) { s = m[1].trim(); changed = true; } }
    }
    const lines = splitTop(s);
    const plain = makePlain(), speak = makeSpeaker({});
    const out = [], warnings = [], speech = [];
    lines.forEach((line, idx) => {
      try {
        const p = new LParser(ltok(line), line);
        const ast = p.parseLine();
        const text = plain(ast), sp = speak(ast);
        out.push(text);
        speech.push(sp);
        p.warnings.forEach(msg => warnings.push({ line: idx + 1, msg }));
      } catch (e) {
        // Linia nie do rozebrania: przepisujemy ją bez zmian i idziemy dalej
        out.push('`' + line.trim() + '`');
        speech.push('fragment LaTeX bez odczytu');
        warnings.push({ line: idx + 1, msg: `${e.message} — tę linię przepisano bez zmian.`, col: e.pos != null ? e.pos + 1 : null });
      }
    });
    return { text: out.join('\n'), errors: [], warnings, speech: speech.join('. '), latex: s, multi: lines.length > 1 };
  }

  function convert(src, opts = {}) {
    const o = { mul: 'cdot', align: true, comma: false, ...opts };
    const lines = src.split('\n');
    const out = [], errors = [], speech = [];
    const filled = lines.filter(l => l.trim()).length;
    const emit = makeEmitter(o);
    const speak = makeSpeaker(o);
    lines.forEach((line, idx) => {
      if (!line.trim()) return;
      try {
        const ast = new Parser(tokenize(line)).parseLine();
        out.push(emit(ast, o.align && filled > 1));
        speech.push(speak(ast));
      } catch (e) {
        errors.push({ line: idx + 1, msg: e.message, col: e.pos != null ? e.pos + 1 : null });
      }
    });
    let latex = '';
    if (out.length === 1) latex = out[0];
    else if (out.length > 1) {
      const env = o.align ? 'aligned' : 'gathered';
      latex = `\\begin{${env}}\n  ${out.join(' \\\\\n  ')}\n\\end{${env}}`;
    }
    return { latex, errors, lines: out, speech: speech.join('. ') };
  }

  return { convert, fromLatex };
})();
if (typeof module !== 'undefined') module.exports = Conv;
