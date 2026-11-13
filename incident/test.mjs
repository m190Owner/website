import assert from 'node:assert/strict';
import {fresh,rounds,apply,advance,conclusion,valid} from './engine.js';
let paths=0;const outcomes=new Set();
function walk(s){assert(valid(s));if(s.closed){paths++;outcomes.add(conclusion(s).title);assert.equal(s.log.length,6);assert(s.downtime<=s.elapsed+35);return;}for(const c of rounds[s.round].choices){const next=apply(s,c.id);assert.equal(s.log.length,s.round);assert.equal(next.log.length,s.round+1);assert.deepEqual(apply(next,c.id),next);assert(next.trust>=0&&next.trust<=100);walk(advance(next));}}
walk(fresh());assert.equal(paths,729);assert(outcomes.size>=3);assert.deepEqual(apply(fresh(),'invalid'),fresh());assert(!valid(null));assert(!valid({version:1}));
let best=fresh();for(const id of ['target','revoke','scope','verify','honest','gate'])best=advance(apply(best,id));assert(best.clean);assert.equal(conclusion(best).title,'A controlled return.');
let bad=fresh();for(const id of ['wait','reset','remove','snapshot','promise','now'])bad=advance(apply(bad,id));assert(!bad.clean);assert.equal(conclusion(bad).title,'Back online. Not back in control.');console.log(`${paths} complete paths passed; ${outcomes.size} distinct endings; idempotence and replay-state checks passed.`);

assert(!valid({...fresh(),pending:true}));
let unsafe=fresh();for(const id of ["target","revoke","remove","verify","honest","now"])unsafe=advance(apply(unsafe,id));assert(!unsafe.clean);
