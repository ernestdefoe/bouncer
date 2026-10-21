<!doctype html>
<html lang="{{ $locale }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>{{ $heading }} · {{ $forum }}</title>
<style>
:root{color-scheme:light dark;--bg:#f6f7f9;--card:#fff;--text:#1f2937;--muted:#6b7280;--accent:#4f46e5;--line:#e5e7eb}
@media (prefers-color-scheme:dark){:root{--bg:#111318;--card:#1b1e25;--text:#e5e7eb;--muted:#9ca3af;--accent:#818cf8;--line:#2b2f38}}
*{box-sizing:border-box}
body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:16px;background:var(--bg);color:var(--text);font:16px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
main{width:100%;max-width:420px;background:var(--card);border:1px solid var(--line);border-radius:12px;padding:28px 24px;text-align:center}
h1{font-size:20px;margin:0 0 8px}
p{margin:0 0 16px;color:var(--muted)}
.bar{height:6px;border-radius:3px;background:var(--line);overflow:hidden}
.bar span{display:block;height:100%;width:8%;background:var(--accent);transition:width .2s}
.err{color:#dc2626}
@if ($turnstile)
.cf-turnstile{display:flex;justify-content:center;margin-bottom:12px}
@endif
small{display:block;margin-top:18px;color:var(--muted);font-size:13px}
</style>
</head>
<body>
<main>
<h1>{{ $heading }}</h1>
<p id="msg" role="status" @if ($failed) class="err" @endif>{{ $failed ? $failedText : $intro }}</p>
<form id="f" method="post" action="{{ $action }}">
<input type="hidden" name="c" value="{{ $token }}">
<input type="hidden" name="s" id="s" value="">
@if ($turnstile)
<div class="cf-turnstile" data-sitekey="{{ $turnstile }}" data-callback="bouncerDone"></div>
@else
<div class="bar" aria-hidden="true"><span id="bar"></span></div>
@endif
</form>
<noscript><p class="err">{{ $noscript }}</p></noscript>
<small>{{ $footer }}</small>
</main>
@if ($turnstile)
<script>function bouncerDone(){document.getElementById('f').submit();}</script>
<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
@else
<script>
(function(){
var c=@json($token),d={{ (int) $difficulty }},bar=document.getElementById('bar');
var K=new Uint32Array([0x428a2f98,0x71374491,0xb5c0fbcf,0xe9b5dba5,0x3956c25b,0x59f111f1,0x923f82a4,0xab1c5ed5,0xd807aa98,0x12835b01,0x243185be,0x550c7dc3,0x72be5d74,0x80deb1fe,0x9bdc06a7,0xc19bf174,0xe49b69c1,0xefbe4786,0x0fc19dc6,0x240ca1cc,0x2de92c6f,0x4a7484aa,0x5cb0a9dc,0x76f988da,0x983e5152,0xa831c66d,0xb00327c8,0xbf597fc7,0xc6e00bf3,0xd5a79147,0x06ca6351,0x14292967,0x27b70a85,0x2e1b2138,0x4d2c6dfc,0x53380d13,0x650a7354,0x766a0abb,0x81c2c92e,0x92722c85,0xa2bfe8a1,0xa81a664b,0xc24b8b70,0xc76c51a3,0xd192e819,0xd6990624,0xf40e3585,0x106aa070,0x19a4c116,0x1e376c08,0x2748774c,0x34b0bcb5,0x391c0cb3,0x4ed8aa4a,0x5b9cca4f,0x682e6ff3,0x748f82ee,0x78a5636f,0x84c87814,0x8cc70208,0x90befffa,0xa4506ceb,0xbef9a3f7,0xc67178f2]);
var W=new Uint32Array(64);
// The first 32 bits of SHA-256 of an ASCII string: enough to count leading zeros.
function first(s){
var l=s.length,n=(((l+8)>>6)+1)*16,m=new Uint32Array(n),i,j;
for(i=0;i<l;i++)m[i>>2]|=s.charCodeAt(i)<<(24-(i&3)*8);
m[l>>2]|=0x80<<(24-(l&3)*8);m[n-1]=l*8;
var A=0x6a09e667,B=0xbb67ae85,C=0x3c6ef372,D=0xa54ff53a,E=0x510e527f,F=0x9b05688c,G=0x1f83d9ab,H=0x5be0cd19;
for(j=0;j<n;j+=16){
for(i=0;i<16;i++)W[i]=m[j+i];
for(;i<64;i++){var x=W[i-15],y=W[i-2];W[i]=(((x>>>7|x<<25)^(x>>>18|x<<14)^(x>>>3))+W[i-7]+((y>>>17|y<<15)^(y>>>19|y<<13)^(y>>>10))+W[i-16])|0;}
var a=A,b=B,cc=C,dd=D,e=E,f=F,g=G,h=H;
for(i=0;i<64;i++){
var t1=(h+((e>>>6|e<<26)^(e>>>11|e<<21)^(e>>>25|e<<7))+((e&f)^(~e&g))+K[i]+W[i])|0;
var t2=(((a>>>2|a<<30)^(a>>>13|a<<19)^(a>>>22|a<<10))+((a&b)^(a&cc)^(b&cc)))|0;
h=g;g=f;f=e;e=(dd+t1)|0;dd=cc;cc=b;b=a;a=(t1+t2)|0;}
A=(A+a)|0;B=(B+b)|0;C=(C+cc)|0;D=(D+dd)|0;E=(E+e)|0;F=(F+f)|0;G=(G+g)|0;H=(H+h)|0;}
return A>>>0;}
var p=c+':',k=0,want=Math.pow(2,d);
function run(){
for(var end=k+20000;k<end;k++){if((first(p+k)>>>(32-d))===0){document.getElementById('s').value=k;bar.style.width='100%';document.getElementById('f').submit();return;}}
bar.style.width=Math.min(95,8+87*k/(want*2))+'%';setTimeout(run,0);}
setTimeout(run,0);
})();
</script>
@endif
</body>
</html>
