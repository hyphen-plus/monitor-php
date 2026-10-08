<?php

return [
    /*
    | token ของ monitor source (acm_...) จากแท็บ Monitoring ไม่ตั้ง = ไม่ส่งอะไรเลย
    */
    'token' => env('ALLCLEARLY_MONITOR_TOKEN'),

    'endpoint' => env('ALLCLEARLY_ENDPOINT', 'https://allclearly.com'),

    /*
    | ส่ง http.request ทุก request (รวม 404 ที่ใช้จับบอทหาช่องโหว่) ผ่าน global middleware
    */
    'track_requests' => (bool) env('ALLCLEARLY_TRACK_REQUESTS', true),

    /*
    | path ที่ไม่ต้องส่ง (รูปแบบเดียวกับ $request->is())
    */
    'ignore_paths' => ['up', 'health', 'livewire/*', '_debugbar/*', 'telescope/*', 'horizon/*'],

    /*
    | ส่ง login สำเร็จ/ไม่สำเร็จ และสมัครสมาชิก จาก event ของ Laravel Auth อัตโนมัติ
    */
    'track_auth' => (bool) env('ALLCLEARLY_TRACK_AUTH', true),

    /*
    | ส่ง app.error เมื่อมี exception ที่ไม่ใช่ HTTP 4xx (ส่งแค่ชื่อ class ไม่ส่ง message)
    */
    'track_errors' => (bool) env('ALLCLEARLY_TRACK_ERRORS', true),

    /*
    | ชื่อ Gate ability ที่บอกว่าเป็นบัญชีผู้ดูแล เช่น 'viewNova' หรือ 'admin'
    | ใช้ตรวจผู้ดูแล login จากประเทศใหม่ null = ไม่ส่งฟิลด์ admin
    */
    'admin_ability' => env('ALLCLEARLY_ADMIN_ABILITY'),

    /*
    | true = เชื่อ CF-Connecting-IP / X-Forwarded-For เมื่อเว็บอยู่หลัง Cloudflare หรือ load balancer
    | ตั้ง false ถ้า request มาตรงจากอินเทอร์เน็ต (header เหล่านี้ปลอมได้)
    */
    'trust_proxy_headers' => (bool) env('ALLCLEARLY_TRUST_PROXY_HEADERS', true),

    'timeout' => 2.0,
];
