<?php
declare(strict_types=1);

$envSupabaseUrl = getenv('ORIMI_SUPABASE_URL');
if ($envSupabaseUrl === false || trim((string)$envSupabaseUrl) === '') {
    $envSupabaseUrl = getenv('SUPABASE_URL');
}

$envSupabaseServiceKey = getenv('ORIMI_SUPABASE_SERVICE_KEY');
if ($envSupabaseServiceKey === false || trim((string)$envSupabaseServiceKey) === '') {
    $envSupabaseServiceKey = getenv('SUPABASE_SERVICE_KEY');
}

define('SUPABASE_URL', ($envSupabaseUrl !== false && trim((string)$envSupabaseUrl) !== '')
    ? trim((string)$envSupabaseUrl)
    : 'https://your-project-ref.supabase.co');
define('SUPABASE_SERVICE_KEY', ($envSupabaseServiceKey !== false && trim((string)$envSupabaseServiceKey) !== '')
    ? trim((string)$envSupabaseServiceKey)
    : 'YOUR_SUPABASE_SERVICE_KEY');
define('SUPABASE_TABLE', 'orimi_waitlist');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $isJson = stripos($contentType, 'application/json') !== false;
    $isAjax = (
        (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
        $isJson
    );

    if (!$isAjax) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid request type']);
        exit;
    }

    try {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            throw new RuntimeException('Empty request body');
        }

        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid JSON payload');
        }

        $mode = isset($data['mode']) ? trim((string)$data['mode']) : '';
        if ($mode !== 'lead' && $mode !== 'full') {
            throw new RuntimeException('Invalid mode');
        }

        $firstName = trim((string)($data['first_name'] ?? ''));
        $email = strtolower(trim((string)($data['email'] ?? '')));

        if ($firstName === '') {
            throw new RuntimeException('first_name is required');
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Valid email is required');
        }

        $now = gmdate('c');

        $payload = [
            'first_name' => $firstName,
            'email' => $email,
            'updated_at' => $now,
        ];

        if ($mode === 'lead') {
            $payload['created_at'] = $now;
        }

        if ($mode === 'full') {
            $payload['phone'] = trim((string)($data['phone'] ?? ''));
            $payload['journey_stage'] = trim((string)($data['journey_stage'] ?? ''));

            $needs = $data['needs'] ?? [];
            if (is_array($needs)) {
                $payload['needs'] = $needs;
            } else {
                $payload['needs'] = json_encode($needs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            $payload['currently_using'] = trim((string)($data['currently_using'] ?? ''));
            $payload['willingness_to_pay'] = trim((string)($data['willingness_to_pay'] ?? ''));
            $payload['partner_involved'] = trim((string)($data['partner_involved'] ?? ''));
            $payload['source'] = trim((string)($data['source'] ?? ''));
            $payload['referrer'] = trim((string)($data['referrer'] ?? ''));
            $payload['page_url'] = trim((string)($data['page_url'] ?? ''));
            $payload['user_agent'] = trim((string)($data['user_agent'] ?? ''));
        }

        $supabaseUrl = trim((string)SUPABASE_URL);
        $supabaseServiceKey = trim((string)SUPABASE_SERVICE_KEY);
        $isPlaceholderUrl = $supabaseUrl === '' || $supabaseUrl === 'https://your-project-ref.supabase.co';
        $isPlaceholderServiceKey = $supabaseServiceKey === '' || $supabaseServiceKey === 'YOUR_SUPABASE_SERVICE_KEY';

        if ($isPlaceholderUrl || $isPlaceholderServiceKey) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Server configuration incomplete']);
            exit;
        }

        $endpoint = rtrim($supabaseUrl, '/') . '/rest/v1/' . SUPABASE_TABLE . '?on_conflict=email';

        $ch = curl_init($endpoint);
        if ($ch === false) {
            throw new RuntimeException('Unable to initialize request');
        }

        $body = json_encode([$payload], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new RuntimeException('Failed to encode payload');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'apikey: ' . $supabaseServiceKey,
                'Authorization: Bearer ' . $supabaseServiceKey,
                'Prefer: resolution=merge-duplicates,return=minimal',
            ],
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_TIMEOUT => 20,
        ]);

        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($resp === false || $curlErr !== '') {
            throw new RuntimeException($curlErr !== '' ? $curlErr : 'Supabase request failed');
        }

        if ($code < 200 || $code >= 300) {
            $decoded = json_decode($resp, true);
            $details = '';

            if (is_array($decoded)) {
                $details = (string)($decoded['message'] ?? $decoded['error_description'] ?? $decoded['error'] ?? $decoded['hint'] ?? '');
            }

            if ($details === '' && is_string($resp)) {
                $details = trim($resp);
            }

            http_response_code($code > 0 ? $code : 502);
            echo json_encode([
                'ok' => false,
                'error' => 'Supabase error',
                'status' => $code,
                'details' => $details,
            ]);
            exit;
        }

        echo json_encode(['ok' => true]);
        exit;
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ORIMI — Your Doula. In Your Pocket. Every Day.</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;1,300;1,400;1,500&family=DM+Sans:opsz,wght@9..40,200;9..40,300;9..40,400;9..40,500&display=swap" rel="stylesheet">
<style>
:root{
  --bg:#050403;--bg2:#0C0906;--bg3:#130E08;
  --gold:#C4923A;--gold2:#E8B86D;--gold3:#8B6030;
  --cream:#F2EDE4;--cream2:#C8BEA8;
  --green2:#5A9E56;
  --muted:rgba(242,237,228,0.45);
  --border:rgba(196,146,58,0.15);
}
*{box-sizing:border-box;margin:0;padding:0;}
html{scroll-behavior:smooth;}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--cream);overflow-x:hidden;cursor:none;}

/* CURSOR */
#cur{width:7px;height:7px;background:var(--gold);border-radius:50%;position:fixed;pointer-events:none;z-index:9999;transform:translate(-50%,-50%);}
#cur-ring{width:34px;height:34px;border:1px solid rgba(196,146,58,.35);border-radius:50%;position:fixed;pointer-events:none;z-index:9998;transform:translate(-50%,-50%);transition:width .15s,height .15s,border-color .15s;}
@media(max-width:900px){#cur,#cur-ring{display:none;}body{cursor:auto;}}

/* CANVAS */
#bg-canvas{position:fixed;inset:0;z-index:0;pointer-events:none;}

/* GRAIN */
body::after{content:'';position:fixed;inset:0;background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='.03'/%3E%3C/svg%3E");pointer-events:none;z-index:9990;opacity:.5;}

/* NAV */
nav{position:fixed;top:0;left:0;right:0;z-index:500;padding:24px 52px;display:flex;justify-content:space-between;align-items:center;transition:all .4s;}
nav.scrolled{background:rgba(5,4,3,.92);backdrop-filter:blur(20px);border-bottom:1px solid var(--border);padding:16px 52px;}
.nav-logo{font-family:'Cormorant Garamond',serif;font-size:20px;font-weight:400;letter-spacing:5px;text-transform:uppercase;background:linear-gradient(135deg,var(--cream) 0%,var(--gold2) 100%);-webkit-background-clip:text;-webkit-text-fill-color:transparent;}
.nav-btn{background:transparent;border:1px solid var(--border);color:var(--cream2);padding:10px 26px;border-radius:100px;font-size:11px;font-family:'DM Sans',sans-serif;font-weight:400;letter-spacing:1.5px;text-transform:uppercase;cursor:pointer;transition:all .3s;}
.nav-btn:hover{border-color:var(--gold);color:var(--gold2);box-shadow:0 0 20px rgba(196,146,58,.15);}

/* PAGE */
.page{position:relative;z-index:1;}

/* ===== TEXT ANIMATION CLASSES ===== */
/* Word slide-up (safe — works on any text) */
.wrev{display:inline;}
.wrev .wr{display:inline-block;overflow:hidden;vertical-align:bottom;}
.wrev .wr .wi{display:inline-block;transform:translateY(110%);opacity:0;transition:transform .7s cubic-bezier(.16,1,.3,1),opacity .5s ease;}
.wrev.go .wi{transform:translateY(0);opacity:1;}

/* Fade-up block */
.fup{opacity:0;transform:translateY(28px);transition:opacity .85s ease,transform .85s cubic-bezier(.16,1,.3,1);}
.fup.go{opacity:1;transform:translateY(0);}

/* Blur dissolve */
.bdis{opacity:0;filter:blur(10px);transition:opacity .7s ease,filter .7s ease;}
.bdis.go{opacity:1;filter:blur(0);}

/* Slide from left */
.sleft{opacity:0;transform:translateX(-32px);transition:opacity .7s ease,transform .7s cubic-bezier(.16,1,.3,1);}
.sleft.go{opacity:1;transform:translateX(0);}

/* Scale pop */
.spop{opacity:0;transform:scale(.88);transition:opacity .7s ease,transform .7s cubic-bezier(.16,1,.3,1);}
.spop.go{opacity:1;transform:scale(1);}

/* HERO */
.hero{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:120px 52px 80px;position:relative;overflow:hidden;}
.hero-inner{position:relative;z-index:1;max-width:900px;width:100%;}
.hero-badge{display:inline-flex;align-items:center;gap:10px;border:1px solid rgba(196,146,58,.2);background:rgba(196,146,58,.05);backdrop-filter:blur(8px);padding:8px 20px;border-radius:100px;font-size:10px;font-weight:500;letter-spacing:2.5px;text-transform:uppercase;color:var(--gold2);margin-bottom:44px;opacity:0;animation:fadeUp .8s .3s ease forwards;}
.ey-dot{width:5px;height:5px;border-radius:50%;background:var(--green2);box-shadow:0 0 8px var(--green2);animation:blink 2s infinite;}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.3}}
@keyframes fadeUp{from{opacity:0;transform:translateY(18px)}to{opacity:1;transform:translateY(0)}}

/* Hero title — line by line slide */
.hero-title{font-family:'Cormorant Garamond',serif;line-height:1.0;letter-spacing:-2px;margin-bottom:0;}
.ht-line{display:block;overflow:hidden;margin-bottom:2px;}
.ht-li{display:block;transform:translateY(110%);opacity:0;font-weight:300;}
.ht-li.plain{background:linear-gradient(135deg,var(--cream) 0%,var(--cream2) 100%);-webkit-background-clip:text;-webkit-text-fill-color:transparent;}
.ht-li.gold{font-style:italic;background:linear-gradient(135deg,var(--gold3) 0%,var(--gold) 45%,var(--gold2) 100%);-webkit-background-clip:text;-webkit-text-fill-color:transparent;}
.ht-sz{font-size:clamp(62px,9.5vw,118px);}
.ht-revealed .ht-li{transform:translateY(0);opacity:1;transition:transform .9s cubic-bezier(.16,1,.3,1),opacity .6s ease;}

.hero-sub-wrap{margin:36px 0 44px;max-width:540px;}
.hero-sub{font-size:17px;color:var(--muted);line-height:1.8;font-weight:300;opacity:0;animation:fadeUp .8s 1.1s ease forwards;}
.hero-sub2{font-size:13px;color:rgba(242,237,228,.28);font-style:italic;line-height:1.6;margin-top:8px;font-weight:300;opacity:0;animation:fadeUp .8s 1.3s ease forwards;}
.hero-cta{display:flex;gap:14px;align-items:center;flex-wrap:wrap;opacity:0;animation:fadeUp .8s 1.4s ease forwards;}
.btn-glow{background:linear-gradient(135deg,var(--gold3),var(--gold),var(--gold2));color:#050403;border:none;padding:16px 36px;border-radius:100px;font-size:13px;font-family:'DM Sans',sans-serif;font-weight:600;cursor:pointer;letter-spacing:.5px;transition:all .3s;position:relative;overflow:hidden;}
.btn-glow::before{content:'';position:absolute;inset:0;background:linear-gradient(135deg,transparent,rgba(255,255,255,.15),transparent);transform:translateX(-100%);transition:transform .55s;}
.btn-glow:hover::before{transform:translateX(100%);}
.btn-glow:hover{transform:translateY(-2px);box-shadow:0 8px 40px rgba(196,146,58,.45);}
.btn-ghost{background:transparent;color:var(--cream2);border:1px solid var(--border);padding:16px 28px;border-radius:100px;font-size:11px;font-family:'DM Sans',sans-serif;font-weight:400;cursor:pointer;letter-spacing:1.5px;text-transform:uppercase;transition:all .3s;}
.btn-ghost:hover{border-color:var(--gold);color:var(--gold2);}
.hero-proof{margin-top:36px;display:flex;align-items:center;gap:12px;font-size:12px;color:rgba(242,237,228,.28);opacity:0;animation:fadeUp .8s 1.6s ease forwards;}
.proof-avs{display:flex;}
.proof-av{width:26px;height:26px;border-radius:50%;border:1.5px solid var(--bg);margin-left:-7px;display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:600;color:white;}
.proof-av:first-child{margin-left:0;background:linear-gradient(135deg,#8B3A3A,#C4923A);}
.proof-av:nth-child(2){background:linear-gradient(135deg,#3D6B3A,#5A9E56);}
.proof-av:nth-child(3){background:linear-gradient(135deg,#3A6B8B,#8B6030);}
.proof-av:nth-child(4){background:linear-gradient(135deg,#6B3A8B,#C4923A);}

/* Floating tags */
.hero-tags{position:absolute;right:-20px;top:50%;transform:translateY(-50%);display:flex;flex-direction:column;gap:10px;opacity:0;animation:fadeUp .8s 1.8s ease forwards;}
@media(max-width:1100px){.hero-tags{display:none;}}
.htag{background:rgba(196,146,58,.05);border:1px solid rgba(196,146,58,.12);padding:8px 16px;border-radius:100px;font-size:10px;letter-spacing:1.5px;text-transform:uppercase;color:rgba(196,146,58,.6);white-space:nowrap;animation:tagF 4s ease-in-out infinite;}
.htag:nth-child(2){animation-delay:.5s;}.htag:nth-child(3){animation-delay:1s;}.htag:nth-child(4){animation-delay:1.5s;}
@keyframes tagF{0%,100%{transform:translateX(0)}50%{transform:translateX(-5px)}}

/* Scroll hint */
.scroll-hint{position:absolute;bottom:36px;left:52px;display:flex;align-items:center;gap:12px;font-size:10px;letter-spacing:2px;text-transform:uppercase;color:rgba(242,237,228,.2);opacity:0;animation:fadeUp .8s 2s ease forwards;}
.sh-line{width:40px;height:1px;background:linear-gradient(to right,rgba(196,146,58,.4),transparent);}

/* SECTIONS */
section{padding:120px 52px;position:relative;z-index:1;}
.sw{max-width:1100px;margin:0 auto;}
.lbl{font-size:10px;font-weight:600;letter-spacing:3px;text-transform:uppercase;color:var(--gold);margin-bottom:18px;display:flex;align-items:center;gap:12px;}
.lbl::before{content:'';width:20px;height:1px;background:var(--gold);flex-shrink:0;}
.ttl{font-family:'Cormorant Garamond',serif;font-size:clamp(38px,5vw,64px);font-weight:300;line-height:1.05;letter-spacing:-1px;margin-bottom:22px;color:var(--cream);}
.ttl em{font-style:italic;color:var(--gold2);}
.sub{font-size:16px;color:var(--muted);line-height:1.8;font-weight:300;}
.gp{background:rgba(255,255,255,.03);backdrop-filter:blur(20px);border:1px solid rgba(255,255,255,.06);border-radius:24px;padding:52px 48px;box-shadow:0 4px 60px rgba(0,0,0,.35),inset 0 1px 0 rgba(255,255,255,.04);}

/* PROBLEM */
.prob-grid{display:grid;grid-template-columns:1fr 1fr;gap:68px;align-items:center;}
.sr{display:flex;gap:22px;align-items:baseline;padding:22px 0;border-bottom:1px solid rgba(255,255,255,.05);transition:all .3s;cursor:default;}
.sr:first-child{border-top:1px solid rgba(255,255,255,.05);}
.sr:hover{padding-left:10px;}
.sn{font-family:'Cormorant Garamond',serif;font-size:56px;font-weight:300;background:linear-gradient(135deg,var(--gold3),var(--gold2));-webkit-background-clip:text;-webkit-text-fill-color:transparent;line-height:1;min-width:92px;flex-shrink:0;}
.st{font-size:13px;color:var(--muted);line-height:1.6;font-weight:300;}

/* CULTURAL */
.cg{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-top:44px;}
.cc{background:rgba(255,255,255,.025);border:1px solid rgba(255,255,255,.06);border-radius:18px;padding:28px 22px;transition:all .4s;position:relative;overflow:hidden;}
.cc::after{content:'';position:absolute;inset:0;background:radial-gradient(circle at 50% 0%,rgba(196,146,58,.07),transparent 65%);opacity:0;transition:opacity .4s;}
.cc:hover{border-color:rgba(196,146,58,.2);transform:translateY(-6px);box-shadow:0 20px 50px rgba(0,0,0,.3);}
.cc:hover::after{opacity:1;}
.ci{font-size:26px;margin-bottom:14px;}
.ct{font-family:'Cormorant Garamond',serif;font-size:20px;font-weight:400;color:var(--cream);margin-bottom:8px;}
.cd{font-size:12px;color:var(--muted);line-height:1.65;font-weight:300;}

/* ACTIONS */
.ag{display:grid;grid-template-columns:repeat(5,1fr);gap:1px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.05);border-radius:20px;overflow:hidden;margin-top:48px;}
.ac{background:var(--bg2);padding:32px 22px;transition:all .4s;position:relative;overflow:hidden;}
.ac::before{content:'';position:absolute;bottom:0;left:0;right:0;height:2px;background:linear-gradient(to right,var(--gold3),var(--gold2));transform:scaleX(0);transition:transform .4s;transform-origin:left;}
.ac:hover{background:rgba(196,146,58,.04);}
.ac:hover::before{transform:scaleX(1);}
.an{font-family:'Cormorant Garamond',serif;font-size:48px;font-weight:300;color:rgba(196,146,58,.07);line-height:1;margin-bottom:16px;}
.at{font-size:9px;font-weight:700;letter-spacing:2.5px;text-transform:uppercase;color:var(--gold);margin-bottom:10px;display:block;}
.atl{font-family:'Cormorant Garamond',serif;font-size:19px;font-weight:400;color:var(--cream);margin-bottom:10px;line-height:1.2;}
.ad{font-size:11px;color:var(--muted);line-height:1.7;font-weight:300;}

/* HOW */
.hg{display:grid;grid-template-columns:repeat(3,1fr);gap:1px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.05);border-radius:20px;overflow:hidden;margin-top:48px;}
.hs{background:var(--bg2);padding:44px 34px;position:relative;overflow:hidden;transition:background .3s;}
.hs:hover{background:rgba(196,146,58,.03);}
.hs-n{font-family:'Cormorant Garamond',serif;font-size:100px;font-weight:300;color:rgba(196,146,58,.04);line-height:1;position:absolute;top:12px;right:16px;}
.hs-ic{width:44px;height:44px;border-radius:12px;border:1px solid var(--border);background:rgba(196,146,58,.06);display:flex;align-items:center;justify-content:center;font-size:18px;margin-bottom:22px;}
.hs-t{font-family:'Cormorant Garamond',serif;font-size:27px;font-weight:400;color:var(--cream);margin-bottom:12px;}
.hs-d{font-size:13px;color:var(--muted);line-height:1.7;font-weight:300;}

/* PARTNER */
.ps-g{display:grid;grid-template-columns:repeat(2,1fr);gap:12px;margin-bottom:32px;}
.ps{background:rgba(255,255,255,.025);border:1px solid rgba(255,255,255,.06);border-radius:16px;padding:26px 24px;transition:border-color .3s;}
.ps:hover{border-color:var(--border);}
.ps-n{font-family:'Cormorant Garamond',serif;font-size:40px;font-weight:300;background:linear-gradient(135deg,var(--gold3),var(--gold2));-webkit-background-clip:text;-webkit-text-fill-color:transparent;line-height:1;margin-bottom:10px;}
.ps-t{font-size:12px;color:var(--muted);line-height:1.6;font-weight:300;margin-bottom:8px;}
.ps-s{font-size:10px;color:rgba(242,237,228,.2);font-style:italic;}
.pb{background:rgba(255,255,255,.025);border:1px solid rgba(255,255,255,.06);border-radius:18px;padding:36px 38px;}
.pb-g{display:grid;grid-template-columns:1fr 1fr;gap:40px;align-items:center;}
.pi{display:flex;gap:12px;align-items:flex-start;margin-bottom:14px;}
.pi-d{width:6px;height:6px;border-radius:50%;background:var(--gold2);flex-shrink:0;margin-top:6px;box-shadow:0 0 8px rgba(196,146,58,.4);}
.pi-t{font-size:13px;font-weight:500;color:var(--cream);margin-bottom:3px;}
.pi-s{font-size:11px;color:var(--muted);font-weight:300;line-height:1.5;}

/* DEMO */
.dg{display:grid;grid-template-columns:1fr 1fr;gap:80px;align-items:center;}
 .pw{position:relative;display:flex;justify-content:center;}
 .ph-halo{position:absolute;width:300px;height:300px;border-radius:50%;background:radial-gradient(circle,rgba(196,146,58,.12) 0%,transparent 70%);top:50%;left:50%;transform:translate(-50%,-50%);filter:blur(24px);}
 .ph-shell{position:relative;z-index:1;padding:2px;border-radius:46px;background:linear-gradient(160deg,rgba(255,255,255,.34) 0%,rgba(255,255,255,.08) 18%,rgba(30,24,18,.95) 45%,rgba(12,9,6,.98) 100%);box-shadow:0 40px 100px rgba(0,0,0,.58),0 8px 24px rgba(0,0,0,.35),inset 0 1px 0 rgba(255,255,255,.22),inset 0 -2px 0 rgba(0,0,0,.45);}
 .ph-shell::before{content:'';position:absolute;inset:8px;border-radius:38px;border:1px solid rgba(255,255,255,.08);pointer-events:none;}
 .ph-shell::after{content:'';position:absolute;top:10px;left:14px;right:14px;height:45%;border-radius:30px;background:linear-gradient(110deg,rgba(255,255,255,.18) 0%,rgba(255,255,255,.04) 24%,transparent 46%);pointer-events:none;mix-blend-mode:screen;}
 .ph-btn{position:absolute;left:-3px;width:3px;border-radius:3px;background:linear-gradient(to bottom,rgba(220,220,220,.45),rgba(30,30,30,.85));box-shadow:0 0 0 1px rgba(0,0,0,.35);}
 .ph-btn.vup{top:118px;height:36px;}
 .ph-btn.vdown{top:160px;height:44px;}
 .ph-btn.power{left:auto;right:-3px;top:144px;height:56px;}
 .ph{width:272px;position:relative;background:radial-gradient(120% 120% at 10% 0%,rgba(196,146,58,.08),transparent 42%),linear-gradient(180deg,#11100D 0%,#090806 100%);border:1px solid rgba(255,255,255,.08);border-radius:42px;padding:16px 16px 22px;overflow:hidden;box-shadow:inset 0 0 0 1px rgba(0,0,0,.6),inset 0 1px 0 rgba(255,255,255,.05);}
 .ph::after{content:'';position:absolute;inset:0;border-radius:42px;pointer-events:none;background:radial-gradient(130% 80% at 85% 100%,rgba(196,146,58,.08),transparent 55%);}
 .ph-notch{width:104px;height:26px;background:linear-gradient(180deg,rgba(17,17,17,.98),rgba(6,6,6,.98));border:1px solid rgba(255,255,255,.06);border-radius:18px;margin:0 auto 14px;position:relative;box-shadow:inset 0 1px 0 rgba(255,255,255,.08);}
 .ph-notch::before{content:'';position:absolute;top:8px;left:18px;width:52px;height:6px;border-radius:100px;background:rgba(255,255,255,.08);}
 .ph-notch::after{content:'';position:absolute;top:7px;right:14px;width:9px;height:9px;border-radius:50%;background:radial-gradient(circle at 35% 35%,rgba(90,158,86,.8),rgba(18,40,22,.95) 70%);box-shadow:0 0 8px rgba(90,158,86,.45);}
.ph-hdr{text-align:center;padding-bottom:14px;border-bottom:1px solid rgba(255,255,255,.05);margin-bottom:14px;}
.ph-hdr p{font-size:10px;color:var(--muted);margin-bottom:2px;}
.ph-hdr h4{font-size:13px;color:var(--cream);font-weight:400;}
 .pm{margin-bottom:9px;position:relative;z-index:1;}
.pm-o{background:rgba(196,146,58,.1);border:1px solid rgba(196,146,58,.1);border-radius:14px 14px 14px 3px;padding:10px 12px;font-size:11px;color:var(--cream2);line-height:1.6;font-family:'DM Sans',sans-serif;font-weight:300;}
.pm-m{background:rgba(255,255,255,.05);border-radius:14px 14px 3px 14px;padding:10px 12px;font-size:11px;color:rgba(242,237,228,.55);line-height:1.6;font-family:'DM Sans',sans-serif;font-weight:300;margin-left:22px;}
.pm-t{font-size:9px;color:rgba(242,237,228,.2);margin-top:4px;padding:0 4px;}

/* WAITLIST */
.wl-wrap{padding:100px 52px;position:relative;z-index:1;}
.wl-g{background:rgba(255,255,255,.035);backdrop-filter:blur(30px);border:1px solid rgba(255,255,255,.07);border-radius:28px;padding:72px 60px;max-width:660px;margin:0 auto;text-align:center;position:relative;overflow:hidden;}
.wl-g::before{content:'';position:absolute;top:-80px;left:50%;transform:translateX(-50%);width:500px;height:500px;border-radius:50%;background:radial-gradient(circle,rgba(196,146,58,.07) 0%,transparent 65%);filter:blur(40px);pointer-events:none;}
.wl-lbl{font-size:10px;font-weight:600;letter-spacing:3px;text-transform:uppercase;color:var(--gold2);margin-bottom:18px;display:flex;align-items:center;justify-content:center;gap:12px;}
.wl-lbl::before,.wl-lbl::after{content:'';width:18px;height:1px;background:var(--gold3);}
.wl-ttl{font-family:'Cormorant Garamond',serif;font-size:clamp(40px,5vw,68px);font-weight:300;line-height:1.05;letter-spacing:-1px;margin-bottom:18px;color:var(--cream);}
.wl-ttl em{font-style:italic;color:var(--gold2);}
.wl-sub{font-size:15px;color:var(--muted);line-height:1.8;font-weight:300;margin-bottom:36px;}
.wl-btn{width:100%;padding:18px;background:linear-gradient(135deg,var(--gold3),var(--gold),var(--gold2));color:#050403;border:none;border-radius:14px;font-size:15px;font-family:'DM Sans',sans-serif;font-weight:600;cursor:pointer;letter-spacing:.3px;transition:all .3s;position:relative;overflow:hidden;}
.wl-btn::before{content:'';position:absolute;inset:0;background:linear-gradient(135deg,transparent,rgba(255,255,255,.12),transparent);transform:translateX(-100%);transition:transform .6s;}
.wl-btn:hover::before{transform:translateX(100%);}
.wl-btn:hover{transform:translateY(-2px);box-shadow:0 10px 40px rgba(196,146,58,.4);}
.wl-note{font-size:11px;color:rgba(242,237,228,.2);margin-top:18px;font-style:italic;line-height:1.7;font-weight:300;}

/* FOOTER */
footer{padding:40px 52px 32px;position:relative;z-index:1;}
.ft{background:rgba(255,255,255,.025);border:1px solid rgba(255,255,255,.05);border-radius:20px;padding:36px 44px;max-width:1100px;margin:0 auto;}
.ft-top{display:flex;justify-content:space-between;align-items:flex-start;padding-bottom:24px;border-bottom:1px solid rgba(255,255,255,.05);margin-bottom:20px;}
.ft-logo{font-family:'Cormorant Garamond',serif;font-size:20px;font-weight:400;letter-spacing:4px;text-transform:uppercase;background:linear-gradient(135deg,var(--cream),var(--gold2));-webkit-background-clip:text;-webkit-text-fill-color:transparent;margin-bottom:5px;}
.ft-tag{font-size:11px;color:rgba(242,237,228,.22);font-style:italic;font-weight:300;}
.ft-links{display:flex;gap:22px;flex-wrap:wrap;}
.ft-links a{font-size:11px;color:rgba(242,237,228,.22);text-decoration:none;transition:color .2s;}
.ft-links a:hover{color:var(--gold2);}
.ft-bot{display:flex;justify-content:space-between;align-items:center;}
.ft-copy{font-size:10px;color:rgba(242,237,228,.15);}
.ft-mis{font-size:10px;color:rgba(242,237,228,.15);font-style:italic;max-width:280px;text-align:right;line-height:1.6;}

/* SURVEY */
#sv{display:none;position:fixed;inset:0;z-index:800;background:rgba(5,4,3,.97);backdrop-filter:blur(30px);align-items:center;justify-content:center;padding:20px;}
.sv-w{width:100%;max-width:580px;position:relative;}
.sv-x{position:absolute;top:-52px;right:0;background:transparent;border:1px solid var(--border);color:var(--muted);width:36px;height:36px;border-radius:50%;cursor:pointer;font-size:16px;display:flex;align-items:center;justify-content:center;transition:all .2s;}
.sv-x:hover{border-color:var(--gold);color:var(--gold);}
.sv-bar{height:1px;background:rgba(196,146,58,.1);border-radius:100px;margin-bottom:52px;overflow:hidden;}
.sv-prog{height:100%;background:linear-gradient(to right,var(--gold3),var(--gold2));border-radius:100px;transition:width .6s cubic-bezier(.16,1,.3,1);}
.sv-scr{animation:scIn .45s cubic-bezier(.16,1,.3,1) both;}
@keyframes scIn{from{opacity:0;transform:translateY(22px) scale(.98)}to{opacity:1;transform:translateY(0) scale(1)}}
@keyframes scOut{from{opacity:1;transform:translateY(0)}to{opacity:0;transform:translateY(-18px)}}
.sv-step{font-size:10px;font-weight:600;letter-spacing:3px;text-transform:uppercase;color:var(--gold);margin-bottom:20px;display:flex;align-items:center;gap:10px;}
.sv-step::before{content:'';width:16px;height:1px;background:var(--gold);}
.sv-q{font-family:'Cormorant Garamond',serif;font-size:clamp(30px,5vw,50px);font-weight:300;line-height:1.08;letter-spacing:-.5px;color:var(--cream);margin-bottom:10px;}
.sv-q em{font-style:italic;color:var(--gold2);}
.sv-hint{font-size:13px;color:var(--muted);margin-bottom:34px;font-weight:300;line-height:1.6;}
.sv-inp{width:100%;padding:20px 24px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:16px;font-size:22px;font-family:'Cormorant Garamond',serif;color:var(--cream);outline:none;transition:all .3s;}
.sv-inp:focus{background:rgba(255,255,255,.07);border-color:rgba(196,146,58,.3);box-shadow:0 0 0 4px rgba(196,146,58,.06);}
.sv-inp::placeholder{color:rgba(242,237,228,.18);font-style:italic;}
.sv-note{font-size:11px;color:rgba(242,237,228,.22);margin-top:10px;font-style:italic;}
.sv-acts{display:flex;align-items:center;gap:16px;margin-top:30px;}
.sv-btn{background:linear-gradient(135deg,var(--gold3),var(--gold),var(--gold2));color:#050403;border:none;padding:15px 32px;border-radius:100px;font-size:13px;font-family:'DM Sans',sans-serif;font-weight:600;cursor:pointer;transition:all .3s;display:inline-flex;align-items:center;gap:10px;position:relative;overflow:hidden;}
.sv-btn::before{content:'';position:absolute;inset:0;background:linear-gradient(135deg,transparent,rgba(255,255,255,.12),transparent);transform:translateX(-100%);transition:transform .5s;}
.sv-btn:hover::before{transform:translateX(100%);}
.sv-btn:hover{transform:translateY(-2px);box-shadow:0 8px 28px rgba(196,146,58,.35);}
.sv-arr{display:inline-block;transition:transform .3s;}
.sv-btn:hover .sv-arr{transform:translateX(4px);}
.sv-skip{font-size:12px;color:rgba(242,237,228,.22);cursor:pointer;text-decoration:underline;text-underline-offset:3px;transition:color .2s;}
.sv-skip:hover{color:var(--gold);}
.sv-opts{display:flex;flex-direction:column;gap:8px;}
.sv-opt{display:flex;align-items:center;gap:14px;padding:14px 18px;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.06);border-radius:13px;cursor:pointer;transition:all .25s;font-size:13px;color:var(--muted);font-weight:300;}
.sv-opt:hover{background:rgba(196,146,58,.05);border-color:rgba(196,146,58,.15);color:var(--cream);transform:translateX(4px);}
.sv-opt.sel{background:rgba(196,146,58,.08);border-color:rgba(196,146,58,.3);color:var(--cream2);}
.oc{width:20px;height:20px;border-radius:50%;border:1px solid rgba(196,146,58,.25);flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:10px;transition:all .25s;}
.sv-opt.sel .oc{background:var(--gold2);border-color:var(--gold2);color:#050403;font-weight:700;}
.mn{font-size:11px;color:var(--muted);margin-bottom:14px;font-style:italic;}

/* TRANSITION */
.ts{text-align:center;padding:20px 0;}
.ts-ic{font-size:52px;margin-bottom:26px;display:inline-block;animation:iF 3s ease-in-out infinite;}
@keyframes iF{0%,100%{transform:translateY(0)}50%{transform:translateY(-10px)}}
.ts-t{font-family:'Cormorant Garamond',serif;font-size:clamp(36px,6vw,58px);font-weight:300;color:var(--cream);line-height:1.08;margin-bottom:14px;}
.ts-t em{font-style:italic;color:var(--gold2);}
.ts-s{font-size:15px;color:var(--muted);line-height:1.7;font-weight:300;max-width:420px;margin:0 auto 36px;}

/* FINAL */
.fn{text-align:center;padding:16px 0;}
.fn-r{width:100px;height:100px;margin:0 auto 30px;position:relative;}
.fn-r svg{width:100px;height:100px;transform:rotate(-90deg);}
.fn-em{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:34px;}
.fn-t{font-family:'Cormorant Garamond',serif;font-size:clamp(40px,6vw,64px);font-weight:300;color:var(--cream);line-height:1.05;letter-spacing:-1px;margin-bottom:18px;}
.fn-t em{font-style:italic;color:var(--gold2);}
.fn-m{font-size:15px;color:var(--muted);line-height:1.8;font-weight:300;margin-bottom:10px;max-width:420px;margin-left:auto;margin-right:auto;}
.fn-sig{font-family:'Cormorant Garamond',serif;font-size:22px;font-style:italic;color:var(--gold2);margin:20px 0 30px;}
.fn-steps{background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.06);border-radius:16px;padding:24px;margin-bottom:28px;text-align:left;}
.fn-step{display:flex;gap:14px;align-items:flex-start;padding:10px 0;border-bottom:1px solid rgba(255,255,255,.05);}
.fn-step:last-child{border-bottom:none;padding-bottom:0;}
.fn-sn{width:26px;height:26px;border-radius:50%;background:rgba(196,146,58,.1);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:10px;color:var(--gold2);font-weight:600;flex-shrink:0;}
.fn-sc p:first-child{font-size:12px;color:var(--cream);margin-bottom:2px;}
.fn-sc p:last-child{font-size:11px;color:var(--muted);font-weight:300;line-height:1.5;}
.fn-sh{background:transparent;border:1px solid var(--border);color:var(--gold2);padding:11px 24px;border-radius:100px;font-size:11px;font-family:'DM Sans',sans-serif;letter-spacing:1px;text-transform:uppercase;cursor:pointer;transition:all .3s;}
.fn-sh:hover{background:rgba(196,146,58,.06);border-color:var(--gold);}

/* ANIMATIONS */
.fup{opacity:0;transform:translateY(24px);transition:opacity .85s ease,transform .85s cubic-bezier(.16,1,.3,1);}
.fup.go{opacity:1;transform:translateY(0);}
@keyframes ep{0%{transform:scale(1)}40%{transform:scale(1.5) rotate(10deg)}70%{transform:scale(.9)}100%{transform:scale(1)}}
@keyframes shake{0%,100%{transform:translateX(0)}20%,60%{transform:translateX(-8px)}40%,80%{transform:translateX(8px)}}

/* MOBILE */
@media(max-width:900px){
  nav,nav.scrolled{padding:16px 20px;}
  section{padding:70px 20px;}
  .hero{padding:90px 20px 60px;}
  .ht-sz{font-size:clamp(44px,10vw,70px);}
  .hero-cta{flex-direction:column;align-items:flex-start;}
  .gp{padding:36px 24px;border-radius:20px;}
  .prob-grid,.dg{grid-template-columns:1fr;gap:44px;}
  .cg{grid-template-columns:1fr 1fr;}
  .ag,.hg{grid-template-columns:1fr;}
  .ps-g{grid-template-columns:1fr;}
  .pb-g{grid-template-columns:1fr;gap:28px;}
  .pb{padding:26px 22px;}
  .ph{max-width:300px;margin:0 auto;}
  .wl-wrap{padding:70px 20px;}
  .wl-g{padding:48px 28px;border-radius:22px;}
  footer{padding:28px 20px;}
  .ft{padding:28px 22px;border-radius:18px;}
  .ft-top{flex-direction:column;gap:20px;}
  .ft-bot{flex-direction:column;gap:10px;text-align:center;}
  .ft-mis{text-align:center;max-width:100%;}
  #sv{padding:16px;}
  .sv-q{font-size:clamp(28px,7vw,44px);}
}
@media(max-width:420px){.cg{grid-template-columns:1fr;}.ht-sz{font-size:40px;}}
</style>
</head>
<body>

<div id="cur"></div>
<div id="cur-ring"></div>
<canvas id="bg-canvas"></canvas>

<nav id="nav">
  <div class="nav-logo">ORIMI</div>
  <button class="nav-btn" onclick="openSV()">Join waitlist</button>
</nav>

<div class="page">

<!-- HERO -->
<section class="hero">
  <div class="hero-inner">
    <div class="hero-badge"><span class="ey-dot"></span>Now accepting waitlist</div>
    <div class="hero-title" id="htitle">
      <div class="ht-line ht-sz"><span class="ht-li plain">Your doula.</span></div>
      <div class="ht-line ht-sz"><span class="ht-li gold">In your pocket.</span></div>
      <div class="ht-line ht-sz"><span class="ht-li plain">Every day.</span></div>
    </div>
    <div class="hero-sub-wrap">
      <p class="hero-sub">ORIMI is an AI doula that texts you every morning, honors your culture and traditions, prepares you for every appointment, and never stops showing up — through pregnancy, birth, and postpartum.</p>
      <p class="hero-sub2">Secure early access and stay updated on launch announcements, insights, and upcoming feature previews.</p>
    </div>
    <div class="hero-cta">
      <button class="btn-glow" onclick="openSV()">Join the waitlist →</button>
      <button class="btn-ghost" onclick="document.getElementById('learn').scrollIntoView({behavior:'smooth'})">Learn more</button>
    </div>
    <div class="hero-proof">
      <div class="proof-avs">
        <div class="proof-av">A</div><div class="proof-av">M</div>
        <div class="proof-av">J</div><div class="proof-av">T</div>
      </div>
      <span>200+ mamas already on the waitlist</span>
    </div>
  </div>
  <div class="hero-tags">
    <div class="htag">AI Doula Intelligence</div>
    <div class="htag">Culturally Fluent</div>
    <div class="htag">Partner Support</div>
    <div class="htag">SMS-First</div>
  </div>
  <div class="scroll-hint"><div class="sh-line"></div><span>Scroll to explore</span></div>
</section>

<!-- PROBLEM -->
<section id="learn">
  <div class="sw">
    <div class="gp fup">
      <div class="prob-grid">
        <div>
          <div class="lbl sleft">The reality</div>
          <h2 class="ttl fup">Every mother deserves <em>better than this.</em></h2>
          <p class="sub fup">The maternal health crisis is real, documented, and preventable. ORIMI exists because every mother deserves a doula — not just the ones who can afford $2,000 for in-person support.</p>
        </div>
        <div>
          <div class="sr spop"><div class="sn">3×</div><div class="st">Black mothers are 3 times more likely to die from pregnancy-related causes — and the healthcare system has historically underserved women across many backgrounds</div></div>
          <div class="sr spop" style="transition-delay:.1s"><div class="sn">6%</div><div class="st">Only 6% of births in the US have a doula present — despite evidence that doulas dramatically transform outcomes</div></div>
          <div class="sr spop" style="transition-delay:.2s"><div class="sn">39%</div><div class="st">A doula reduces C-section rates by 39% and significantly lowers the need for pain medication and interventions</div></div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- CULTURAL -->
<section style="padding-top:0;">
  <div class="sw">
    <div class="gp fup">
      <div style="max-width:620px;margin:0 auto 44px;text-align:center;">
        <div class="lbl bdis" style="justify-content:center;">Culturally fluent. Not one-size-fits-all.</div>
        <h2 class="ttl fup">ORIMI meets you <em>where you are.</em></h2>
        <p class="sub fup" style="max-width:100%;">Every mom deserves a doula who speaks her language, honors her traditions, and prepares her to make decisions from knowledge instead of fear. She carries extra vigilance for the communities the healthcare system has historically underserved.</p>
      </div>
      <div class="cg">
        <div class="cc fup"><div class="ci">🌺</div><div class="ct">Cuarentena</div><div class="cd">40 days of rest, warming foods, and family support for Latina moms. ORIMI knows this tradition and honors it.</div></div>
        <div class="cc fup" style="transition-delay:.1s"><div class="ci">🌙</div><div class="ct">Sitting the month</div><div class="cd">Zuò yuèzi — Chinese postpartum confinement of rest, warmth, and nourishment. ORIMI understands.</div></div>
        <div class="cc fup" style="transition-delay:.2s"><div class="ci">🌿</div><div class="ct">Caribbean traditions</div><div class="cd">Lying-in, herbal baths, and community-centered care. ORIMI recognizes what medicine often overlooks.</div></div>
        <div class="cc fup" style="transition-delay:.3s"><div class="ci">✨</div><div class="ct">Your tradition</div><div class="cd">Some moms want science. Some want spirit. Some want both. ORIMI reads your energy and meets you exactly there.</div></div>
      </div>
    </div>
  </div>
</section>

<!-- ACTIONS -->
<section style="padding-top:0;">
  <div class="sw">
    <div class="fup" style="margin-bottom:44px;">
      <div class="lbl sleft">How ORIMI shows up</div>
      <h2 class="ttl fup">Not a reminder app. <em>A doula.</em></h2>
      <p class="sub fup" style="max-width:520px;">Every message ORIMI sends serves one of five purposes. If it doesn't serve one of these, ORIMI doesn't send it.</p>
    </div>
    <div class="ag fup">
      <div class="ac"><div class="an">01</div><span class="at">Educate</span><div class="atl">Teach her what's really happening</div><div class="ad">Not "baby is the size of a mango." Baby's lungs are producing surfactant right now. Specific to her week, her body, her baby.</div></div>
      <div class="ac"><div class="an">02</div><span class="at">Prepare</span><div class="atl">Before every appointment</div><div class="ad">48 hours before the glucose test — what to expect, what to eat, what to wear, and the exact questions to ask her provider.</div></div>
      <div class="ac"><div class="an">03</div><span class="at">Support</span><div class="atl">Hold space. Validate. Stay.</div><div class="ad">"That sounds really hard" before "here's what might help." The thing no app does well but every mama needs.</div></div>
      <div class="ac"><div class="an">04</div><span class="at">Advocate</span><div class="atl">Give her the words</div><div class="ad">The BRAIN framework. Teaching her she has the right to ask why — and that asking is not being difficult.</div></div>
      <div class="ac"><div class="an">05</div><span class="at">Anticipate</span><div class="atl">Before she has to ask</div><div class="ad">At 13 emotional flashpoints — anatomy scan, week 37 labor fear, day 3 postpartum crash — ORIMI reaches out first.</div></div>
    </div>
  </div>
</section>

<!-- HOW -->
<section style="padding-top:0;">
  <div class="sw">
    <div class="fup" style="margin-bottom:44px;">
      <div class="lbl sleft">Getting started</div>
      <h2 class="ttl fup">No app to download. <em>Just your phone.</em></h2>
    </div>
    <div class="hg fup">
      <div class="hs"><div class="hs-n">1</div><div class="hs-ic">📱</div><div class="hs-t">Text to join</div><div class="hs-d">ORIMI works entirely over SMS. No app. No login. Works on any phone. Just your number and your due date.</div></div>
      <div class="hs"><div class="hs-n">2</div><div class="hs-ic">🤱🏾</div><div class="hs-t">ORIMI learns you</div><div class="hs-d">She learns your stage, your provider, your preferences. Every message is personalized to exactly where you are.</div></div>
      <div class="hs"><div class="hs-n">3</div><div class="hs-ic">✦</div><div class="hs-t">She never stops</div><div class="hs-d">Every morning. Before every appointment. Through birth and into the fourth trimester. ORIMI doesn't disappear after month 3.</div></div>
    </div>
  </div>
</section>

<!-- PARTNER -->
<section style="padding-top:0;">
  <div class="sw">
    <div class="gp fup">
      <div style="text-align:center;max-width:600px;margin:0 auto 48px;">
        <div class="lbl bdis" style="justify-content:center;">For the partner too</div>
        <h2 class="ttl fup">She equips her <em>partner too.</em></h2>
        <p class="sub fup" style="max-width:100%;">A partner who knows what to expect, how to advocate, and when to simply hold space isn't just emotionally helpful. Oxytocin — the hormone that drives labor — flows when she feels safe, supported, and unobserved.</p>
      </div>
      <div class="ps-g fup">
        <div class="ps"><div class="ps-n">↑ Oxytocin</div><div class="ps-t">Continuous support promotes maternal relaxation, reduces stress hormones, and enhances oxytocin release — directly contributing to cervical dilation and effective contractions</div><div class="ps-s">Frontiers in Endocrinology, 2021</div></div>
        <div class="ps"><div class="ps-n">↓ Cesarean</div><div class="ps-t">Women with continuous support are significantly less likely to need a cesarean and more likely to give birth spontaneously with shorter labors</div><div class="ps-s">Cochrane Review</div></div>
        <div class="ps"><div class="ps-n">16/18</div><div class="ps-t">Partner-inclusive education programs showed significant reductions in postpartum depression in 16 out of 18 studies reviewed</div><div class="ps-s">Frontiers in Psychiatry, 2024</div></div>
        <div class="ps"><div class="ps-n">$91M</div><div class="ps-t">Doula-model care saved $91M, 219,530 fewer cesareans, and 51 fewer maternal deaths across 1.8 million women</div><div class="ps-s">Oregon Health Authority</div></div>
      </div>
      <div class="pb fup">
        <div class="pb-g">
          <div>
            <div class="lbl sleft">What partners receive</div>
            <h3 style="font-family:'Cormorant Garamond',serif;font-size:clamp(26px,3vw,38px);font-weight:300;color:var(--cream);line-height:1.1;margin-bottom:14px;">Real doula-level guidance. <em style="font-style:italic;color:var(--gold2);">Every week.</em></h3>
            <p style="font-size:14px;color:var(--muted);line-height:1.7;font-weight:300;">ORIMI sends partners weekly updates — what's happening with baby, what she's feeling, one specific thing they can do today, and how to be the support person she actually needs.</p>
          </div>
          <div>
            <div class="pi"><div class="pi-d"></div><div><div class="pi-t">What's happening with baby this week</div><div class="pi-s">Plain language that makes them feel connected to the pregnancy.</div></div></div>
            <div class="pi"><div class="pi-d"></div><div><div class="pi-t">What she's feeling and why</div><div class="pi-s">The back pain is real. The heartburn is biology. Understanding removes helplessness.</div></div></div>
            <div class="pi"><div class="pi-d"></div><div><div class="pi-t">One specific thing to do today</div><div class="pi-s">Warm rice sock on her lower back. Rub her feet for 10 minutes. Concrete, not vague.</div></div></div>
            <div class="pi"><div class="pi-d"></div><div><div class="pi-t">How to hold space in the birth room</div><div class="pi-s">Dim lights. Counter-pressure. Advocate with staff. An informed partner changes outcomes.</div></div></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- SMS DEMO -->
<section style="padding-top:0;">
  <div class="sw">
    <div class="gp fup">
      <div class="dg">
        <div>
          <div class="lbl sleft">What ORIMI sounds like</div>
          <h2 class="ttl fup">A doula who knows <em>you.</em></h2>
          <p class="sub fup">ORIMI doesn't just tell moms when something is happening. She tells them what it is, why it matters, how to prepare, and what comes next.</p>
        </div>
        <div class="pw fup">
          <div class="ph-halo"></div>
          <div class="ph-shell">
            <span class="ph-btn vup"></span>
            <span class="ph-btn vdown"></span>
            <span class="ph-btn power"></span>
            <div class="ph">
              <div class="ph-notch"></div>
              <div class="ph-hdr"><p>Messages</p><h4>ORIMI 🤱🏾</h4></div>
              <div class="pm"><div class="pm-o">Good morning Sasha. Week 32. Baby's brain grew 30% last month. Two eggs covers DHA and choline. Keep protein at 80-100g today. How are you feeling?</div><div class="pm-t">Today 6:30am</div></div>
              <div class="pm" style="display:flex;flex-direction:column;align-items:flex-end;"><div class="pm-m">Feeling nervous about Thursday honestly</div><div class="pm-t">7:14am</div></div>
              <div class="pm"><div class="pm-o">That makes sense. Thursday: check baby's position, doppler heartbeat. Wear a two-piece. Ask: "Is baby head-down yet?"</div><div class="pm-t">7:16am</div></div>
              <div class="pm" style="display:flex;flex-direction:column;align-items:flex-end;"><div class="pm-m">Thank you 🙏 that actually helps</div><div class="pm-t">7:18am</div></div>
              <div class="pm"><div class="pm-o">You've got this. ORIMI will check in after the visit.</div><div class="pm-t">7:19am</div></div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- WAITLIST -->
<div class="wl-wrap" id="waitlist">
  <div class="wl-g fup">
    <div class="wl-lbl">Join the waitlist</div>
    <h2 class="wl-ttl">Be one of the first<br>mothers <em>ORIMI supports.</em></h2>
    <p class="wl-sub">No app needed. Just your phone number and due date. ORIMI will be there before you need her.</p>
    <button class="wl-btn" onclick="openSV()">Join the waitlist →</button>
    <p class="wl-note">"ORIMI is built by a mama who trusts the birthing process. This is not a tech product. This is a doula." — Khylir Patton, Founder</p>
  </div>
</div>

<!-- FOOTER -->
<footer>
  <div class="ft">
    <div class="ft-top">
      <div><div class="ft-logo">ORIMI</div><div class="ft-tag">Your doula. In your pocket. Every day.</div></div>
      <div class="ft-links"><a href="#">Privacy</a><a href="#">Terms</a><a href="#">Contact</a><a href="#">Instagram</a><a href="#">TikTok</a></div>
    </div>
    <div class="ft-bot">
      <div class="ft-copy">© 2026 IYA Inc. All rights reserved.</div>
      <div class="ft-mis">Built by a mama who trusts the birthing process. Because every mother deserves to feel supported, informed, and never alone.</div>
    </div>
  </div>
</footer>
</div>

<!-- SURVEY -->
<div id="sv">
  <div class="sv-w">
    <button class="sv-x" onclick="closeSV()">✕</button>
    <div class="sv-bar"><div class="sv-prog" id="sv-p" style="width:0%"></div></div>
    <div id="sv-con"></div>
  </div>
</div>

<script>
/* ===== 3D CANVAS ===== */
(function(){
  const cv=document.getElementById('bg-canvas');
  const cx=cv.getContext('2d');
  let W,H,mx=0,my=0,t=0;
  const pts=[];

  function resize(){W=cv.width=innerWidth;H=cv.height=innerHeight;}

  class Star{
    constructor(){this.reset();}
    reset(){
      this.x=(Math.random()-0.5)*W*2;
      this.y=(Math.random()-0.5)*H*2;
      this.z=Math.random()*1400+100;
      this.pz=this.z;
      this.vz=Math.random()*.8+.3;
      this.r=Math.random()*1.5+.3;
      const cols=['196,146,58','255,255,255','139,111,62','232,184,109'];
      this.col=cols[Math.floor(Math.random()*cols.length)];
      this.a=Math.random()*.6+.1;
    }
    draw(){
      const px=(this.x/this.z)*800+W/2+(mx-W/2)*.02;
      const py=(this.y/this.z)*800+H/2+(my-H/2)*.02;
      const ppx=(this.x/this.pz)*800+W/2;
      const ppy=(this.y/this.pz)*800+H/2;
      const r=Math.max(.1,(1-this.z/1500)*3*this.r);
      const a=this.a*(1-this.z/1500);
      // draw streak
      cx.beginPath();
      cx.moveTo(ppx,ppy);
      cx.lineTo(px,py);
      cx.strokeStyle=`rgba(${this.col},${a*.6})`;
      cx.lineWidth=r*.5;
      cx.stroke();
      // draw dot
      cx.beginPath();
      cx.arc(px,py,r,0,Math.PI*2);
      cx.fillStyle=`rgba(${this.col},${a})`;
      cx.fill();
      this.pz=this.z;
      this.z-=this.vz;
      if(this.z<1||px<-50||px>W+50||py<-50||py>H+50){this.reset();this.pz=this.z;}
    }
  }

  // Perspective grid
  function drawGrid(){
    t+=.002;
    cx.strokeStyle='rgba(196,146,58,0.028)';
    cx.lineWidth=.5;
    const vx=W/2+(mx-W/2)*.04;
    const vy=H*.65+(my-H/2)*.02;
    const rows=10,cols=14;
    // horizontal
    for(let i=0;i<=rows;i++){
      const p=i/rows;
      const y=H*.4+p*H*.7;
      const xl=vx*(1-p*1.8);
      const xr=W-vx*(1-p*1.8)+W*(p*1.8-1)*0;
      cx.beginPath();
      cx.moveTo(Math.max(-W*.5,vx-W*(.5+p*.8)),y);
      cx.lineTo(Math.min(W*1.5,vx+W*(.5+p*.8)),y);
      cx.stroke();
    }
    // vertical
    for(let i=0;i<=cols;i++){
      const p=(i/cols)-.5;
      cx.beginPath();
      cx.moveTo(vx+p*W*.3,H*.35);
      cx.lineTo(vx+p*W*2.5,H*1.2);
      cx.stroke();
    }
  }

  // Orbs
  function drawOrbs(){
    const o=[
      {x:.12,y:.18,r:280,c:'196,146,58',a:.06},
      {x:.88,y:.82,r:220,c:'61,107,58',a:.045},
      {x:.72,y:.12,r:200,c:'139,111,62',a:.05},
      {x:.18,y:.78,r:180,c:'196,146,58',a:.04},
      {x:.5,y:.5,r:350,c:'50,40,25',a:.04},
    ];
    o.forEach((ob,i)=>{
      const ox=ob.x*W+Math.sin(t+i*1.4)*35;
      const oy=ob.y*H+Math.cos(t+i*1.0)*25;
      const g=cx.createRadialGradient(ox,oy,0,ox,oy,ob.r);
      g.addColorStop(0,`rgba(${ob.c},${ob.a})`);
      g.addColorStop(1,`rgba(${ob.c},0)`);
      cx.beginPath();cx.arc(ox,oy,ob.r,0,Math.PI*2);
      cx.fillStyle=g;cx.fill();
    });
  }

  function loop(){
    cx.clearRect(0,0,W,H);
    cx.fillStyle='#050403';cx.fillRect(0,0,W,H);
    drawOrbs();
    drawGrid();
    pts.forEach(p=>p.draw());
    requestAnimationFrame(loop);
  }

  resize();
  for(let i=0;i<200;i++){const s=new Star();s.z=Math.random()*1400+100;s.pz=s.z;pts.push(s);}
  addEventListener('resize',resize);
  addEventListener('mousemove',e=>{mx=e.clientX;my=e.clientY;});
  loop();
})();

/* ===== CURSOR ===== */
const cur=document.getElementById('cur');
const curR=document.getElementById('cur-ring');
let cx2=0,cy2=0,rx=0,ry=0;
addEventListener('mousemove',e=>{cx2=e.clientX;cy2=e.clientY;cur.style.left=cx2+'px';cur.style.top=cy2+'px';});
(function ac(){rx+=(cx2-rx)*.12;ry+=(cy2-ry)*.12;curR.style.left=rx+'px';curR.style.top=ry+'px';requestAnimationFrame(ac);})();
document.querySelectorAll('button,a,.sv-opt,.ac,.cc,.hs,.sr').forEach(el=>{
  el.addEventListener('mouseenter',()=>{cur.style.transform='translate(-50%,-50%) scale(2)';curR.style.width='50px';curR.style.height='50px';curR.style.borderColor='rgba(196,146,58,.6)';});
  el.addEventListener('mouseleave',()=>{cur.style.transform='translate(-50%,-50%) scale(1)';curR.style.width='34px';curR.style.height='34px';curR.style.borderColor='rgba(196,146,58,.35)';});
});

/* ===== NAV ===== */
addEventListener('scroll',()=>document.getElementById('nav').classList.toggle('scrolled',scrollY>60));

/* ===== HERO TITLE ANIMATION ===== */
window.addEventListener('load',()=>{
  const lines=document.querySelectorAll('#htitle .ht-li');
  lines.forEach((el,i)=>{
    setTimeout(()=>{
      el.style.transition='transform .95s cubic-bezier(.16,1,.3,1),opacity .65s ease';
      el.style.transform='translateY(0)';
      el.style.opacity='1';
    },480+i*160);
  });
});

/* ===== WORD REVEAL (safe — no char splitting of HTML) ===== */
function applyWordReveal(el){
  if(el.dataset.wrinit)return;
  el.dataset.wrinit='1';
  // Store original html safely
  const text=el.innerText;
  const words=text.split(' ');
  el.innerHTML=words.map((w,i)=>`<span class="wr" style="margin-right:.28em"><span class="wi" style="transition-delay:${i*.05}s">${w}</span></span>`).join('');
}

/* ===== SCROLL REVEAL ===== */
const rev=new IntersectionObserver(entries=>{
  entries.forEach(e=>{
    if(!e.isIntersecting)return;
    const el=e.target;
    el.classList.add('go');
    rev.unobserve(el);
  });
},{threshold:.07,rootMargin:'0px 0px -28px 0px'});

document.querySelectorAll('.fup,.bdis,.sleft,.spop').forEach(el=>{
  if(el.closest('#sv'))return;
  rev.observe(el);
});

/* ===== SURVEY STATE ===== */
let S={n:0,name:'',email:'',phone:'',journey:'',needs:[],using:'',price:'',partner:'',saved:false,finalError:'',savingFinal:false};
const T=10;

const screens=[
  ()=>`<div class="sv-step">Step 1 of 3</div><h2 class="sv-q">What's your <em>name?</em></h2><p class="sv-hint">ORIMI will use this to personalize every message.</p><input class="sv-inp" id="si0" type="text" placeholder="First name…" value="${S.name}" autocomplete="given-name"><div class="sv-acts"><button class="sv-btn" onclick="nxt(0)">Continue <span class="sv-arr">→</span></button></div>`,
  ()=>`<div class="sv-step">Step 2 of 3</div><h2 class="sv-q">Best email to <em>reach you?</em></h2><p class="sv-hint">Early access details go here first.</p><input class="sv-inp" id="si1" type="email" placeholder="Your email…" value="${S.email}" autocomplete="email"><div class="sv-acts"><button class="sv-btn" onclick="nxt(1)">Continue <span class="sv-arr">→</span></button></div>`,
  ()=>`<div class="sv-step">Step 3 of 3</div><h2 class="sv-q">Want to be first to <em>text with ORIMI?</em></h2><p class="sv-hint">Drop your number — optional but encouraged.</p><input class="sv-inp" id="si2" type="tel" placeholder="Your number…" value="${S.phone}" autocomplete="tel"><p class="sv-note">We'll only text you about early access. No spam. Unsubscribe anytime.</p><div class="sv-acts"><button class="sv-btn" onclick="nxt(2)">Continue <span class="sv-arr">→</span></button><span class="sv-skip" onclick="nxt(2)">Skip for now</span></div>`,
  ()=>`<div class="ts"><div class="ts-ic">🤱🏾</div><h2 class="ts-t">Thanks, <em>${S.name||'mama'}.</em></h2><p class="ts-s">While you wait for early access, help us build ORIMI around what YOU actually need. 5 quick questions.</p><div class="sv-acts" style="justify-content:center"><button class="sv-btn" onclick="nxt(3)">Let's do it <span class="sv-arr">→</span></button></div></div>`,
  ()=>`<div class="sv-step">Question 1 of 5</div><h2 class="sv-q">Where are you <em>right now?</em></h2><div class="sv-opts">${['Trying to conceive','Pregnant (1st trimester)','Pregnant (2nd trimester)','Pregnant (3rd trimester)','Postpartum (0-3 months)','Postpartum (4-12 months)','Planning a future pregnancy'].map(o=>`<div class="sv-opt${S.journey===o?' sel':''}" onclick="p1('journey',this)"><div class="oc">${S.journey===o?'✓':''}</div>${o}</div>`).join('')}</div><div class="sv-acts" style="margin-top:16px"><button class="sv-btn" onclick="nxt(4)">Next <span class="sv-arr">→</span></button><span class="sv-skip" onclick="nxt(4)">Skip</span></div>`,
  ()=>`<div class="sv-step">Question 2 of 5</div><h2 class="sv-q">What would help you <em>most right now?</em></h2><p class="mn">Pick your top 2.</p><div class="sv-opts">${["Someone to explain what's happening to my body week by week","Help preparing for appointments","Breathing exercises and stress relief","Nutrition and wellness guidance","A birth plan that reflects what I want","Support for my partner so they know how to help","Someone to talk to between appointments","Postpartum recovery and mental health support","Help finding holistic providers"].map(o=>`<div class="sv-opt${S.needs.includes(o)?' sel':''}" onclick="pm(this,2)"><div class="oc">${S.needs.includes(o)?'✓':''}</div>${o}</div>`).join('')}</div><div class="sv-acts" style="margin-top:16px"><button class="sv-btn" onclick="nxt(5)">Next <span class="sv-arr">→</span></button><span class="sv-skip" onclick="nxt(5)">Skip</span></div>`,
  ()=>`<div class="sv-step">Question 3 of 5</div><h2 class="sv-q">What are you <em>currently using?</em></h2><div class="sv-opts">${['A pregnancy tracking app (What to Expect, The Bump, etc.)','A doula (in person)','My OB/midwife office resources','Social media groups or mom communities','Nothing — figuring it out on my own','Other'].map(o=>`<div class="sv-opt${S.using===o?' sel':''}" onclick="p1('using',this)"><div class="oc">${S.using===o?'✓':''}</div>${o}</div>`).join('')}</div><div class="sv-acts" style="margin-top:16px"><button class="sv-btn" onclick="nxt(6)">Next <span class="sv-arr">→</span></button><span class="sv-skip" onclick="nxt(6)">Skip</span></div>`,
  ()=>`<div class="sv-step">Question 4 of 5</div><h2 class="sv-q">What would feel <em>fair to pay monthly?</em></h2><p class="sv-hint" style="margin-bottom:18px">For personalized check-ins, appointment prep, partner guidance, and support from pregnancy through postpartum.</p><div class="sv-opts">${['Under $10/month','$10–20/month','$20–35/month','$35–50/month','$50+/month if it replaced other services',"I'd only use it if it was free"].map(o=>`<div class="sv-opt${S.price===o?' sel':''}" onclick="p1('price',this)"><div class="oc">${S.price===o?'✓':''}</div>${o}</div>`).join('')}</div><div class="sv-acts" style="margin-top:16px"><button class="sv-btn" onclick="nxt(7)">Next <span class="sv-arr">→</span></button><span class="sv-skip" onclick="nxt(7)">Skip</span></div>`,
  ()=>`<div class="sv-step">Question 5 of 5</div><h2 class="sv-q">Is there a partner <em>involved?</em></h2><div class="sv-opts">${["Yes — they'd love that","Yes — but they probably wouldn't sign up on their own","No partner or support person right now","I'm not sure yet"].map(o=>`<div class="sv-opt${S.partner===o?' sel':''}" onclick="p1('partner',this)"><div class="oc">${S.partner===o?'✓':''}</div>${o}</div>`).join('')}</div><div class="sv-acts" style="margin-top:16px"><button class="sv-btn" onclick="nxt(8)" ${S.savingFinal?'disabled style="opacity:.7;pointer-events:none"':''}>${S.savingFinal?'Saving...':'Submit'} <span class="sv-arr">→</span></button><span class="sv-skip" onclick="nxt(8)">Skip</span></div>${S.finalError?`<p class="sv-note" style="color:#e7a2a2;margin-top:12px;">${S.finalError}</p>`:''}`,
  ()=>`<div class="fn"><div class="fn-r"><svg viewBox="0 0 100 100"><circle cx="50" cy="50" r="44" fill="none" stroke="rgba(196,146,58,.1)" stroke-width="1.5"/><circle cx="50" cy="50" r="44" fill="none" stroke="url(#fg)" stroke-width="1.5" stroke-dasharray="276" stroke-dashoffset="276" id="fn-c" style="transition:stroke-dashoffset 1.8s cubic-bezier(.16,1,.3,1) .3s"/><defs><linearGradient id="fg" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#8B6030"/><stop offset="100%" stop-color="#E8B86D"/></linearGradient></defs></svg><div class="fn-em" id="fnem">🤱🏾</div></div><h2 class="fn-t">You're in, <em id="fnn">${S.name||'mama'}.</em></h2><p class="fn-m">We're building ORIMI around moms like you. You'll hear from us soon with early access details.</p><p class="fn-m">In the meantime: you're already doing the most important thing — showing up and asking for support.</p><p class="fn-sig">Talk soon.<br>— ORIMI</p><div class="fn-steps"><div class="fn-step"><div class="fn-sn">1</div><div class="fn-sc"><p>Confirmation email on its way</p><p>Add hello@orimi.com to your contacts.</p></div></div><div class="fn-step"><div class="fn-sn">2</div><div class="fn-sc"><p>ORIMI will text you before your due date</p><p>She'll introduce herself. No spam. Ever.</p></div></div><div class="fn-step"><div class="fn-sn">3</div><div class="fn-sc"><p>Early access before public launch</p><p>Waitlist mamas get in first — at the founding rate.</p></div></div></div><button class="fn-sh" onclick="shr()">Share with a mama who needs this →</button></div>`
];

function render(n){
  const box=document.getElementById('sv-con');
  const old=box.firstChild;
  if(old){old.style.animation='scOut .3s ease forwards';setTimeout(()=>build(n),280);}else build(n);
  document.getElementById('sv-p').style.width=Math.round(n/T*100)+'%';
}
function build(n){
  document.getElementById('sv-con').innerHTML=`<div class="sv-scr">${screens[n]()}</div>`;
  if(n===9){setTimeout(()=>{
    const c=document.getElementById('fn-c');if(c)c.style.strokeDashoffset='0';
    setTimeout(()=>{const e=document.getElementById('fnem');if(e)e.style.animation='ep .6s cubic-bezier(.34,1.56,.64,1) both';},600);
  },150);}
}
async function nxt(c){
  if(c===0){const v=document.getElementById('si0')?.value.trim();if(!v){shk('si0');return;}S.name=v;}
  if(c===1){const v=document.getElementById('si1')?.value.trim();if(!v||!v.includes('@')){shk('si1');return;}S.email=v;if(!S.saved)await saveLead();}
  if(c===2){S.phone=document.getElementById('si2')?.value.trim()||'';}
  if(c===8){
    S.finalError='';
    S.savingFinal=true;
    render(8);
    const ok=await save();
    S.savingFinal=false;
    if(ok){S.n=9;render(9);}else{S.finalError='Save failed. Please retry.';render(8);}
    return;
  }
  S.n=c+1;render(S.n);
}
function p1(field,el){el.closest('.sv-opts').querySelectorAll('.sv-opt').forEach(o=>{o.classList.remove('sel');o.querySelector('.oc').textContent='';});el.classList.add('sel');el.querySelector('.oc').textContent='✓';S[field]=el.innerText.replace('✓','').trim();}
function pm(el,max){
  const isSel=el.classList.contains('sel');
  const sels=[...el.closest('.sv-opts').querySelectorAll('.sv-opt.sel')];
  if(!isSel&&sels.length>=max){sels[0].classList.remove('sel');sels[0].querySelector('.oc').textContent='';const v=sels[0].innerText.replace('✓','').trim();const i=S.needs.indexOf(v);if(i>-1)S.needs.splice(i,1);}
  el.classList.toggle('sel');
  const chk=el.querySelector('.oc');
  const v=el.innerText.replace('✓','').trim();
  if(el.classList.contains('sel')){S.needs.push(v);chk.textContent='✓';}else{const i=S.needs.indexOf(v);if(i>-1)S.needs.splice(i,1);chk.textContent='';}
}
function shk(id){const e=document.getElementById(id);if(!e)return;e.style.animation='shake .4s ease';e.style.borderColor='rgba(200,80,80,.4)';setTimeout(()=>{e.style.animation='';e.style.borderColor='';},400);}
async function saveLead(){
  try{
    const endpoint=window.location.pathname;
    const res=await fetch(endpoint,{
      method:'POST',
      headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
      body:JSON.stringify({mode:'lead',first_name:S.name,email:S.email})
    });
    const out=await res.json().catch(()=>({ok:false,error:'Invalid JSON response'}));
    if(!res.ok||!out.ok){console.error('saveLead failed',out);S.saved=false;return false;}
    S.saved=true;
    return true;
  }catch(e){
    S.saved=false;
    console.error('saveLead request error',e);
    return false;
  }
}
async function save(){
  try{
    const endpoint=window.location.pathname;
    const res=await fetch(endpoint,{
      method:'POST',
      headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
      body:JSON.stringify({
        mode:'full',
        first_name:S.name,
        email:S.email,
        phone:S.phone,
        journey_stage:S.journey,
        needs:S.needs,
        currently_using:S.using,
        willingness_to_pay:S.price,
        partner_involved:S.partner,
        source:'landing_php',
        referrer:document.referrer||'',
        page_url:window.location.href,
        user_agent:navigator.userAgent
      })
    });
    const out=await res.json().catch(()=>({ok:false,error:'Invalid JSON response'}));
    if(!res.ok||!out.ok){console.error('save failed',out);return false;}
    return true;
  }catch(e){
    console.error('save request error',e);
    return false;
  }
}
function openSV(){document.getElementById('sv').style.display='flex';document.body.style.overflow='hidden';S.n=0;render(0);}
function closeSV(){document.getElementById('sv').style.display='none';document.body.style.overflow='';}
function shr(){if(navigator.share)navigator.share({title:'ORIMI',text:'I just joined the ORIMI waitlist — an AI doula for every mother.',url:location.href});else navigator.clipboard.writeText(location.href).then(()=>alert('Link copied!'));}
addEventListener('keydown',e=>{if(e.key==='Escape')closeSV();});
const ss=document.createElement('style');ss.textContent='@keyframes shake{0%,100%{transform:translateX(0)}20%,60%{transform:translateX(-8px)}40%,80%{transform:translateX(8px)}}';document.head.appendChild(ss);
</script>
</body>
</html>
