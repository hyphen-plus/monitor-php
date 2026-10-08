<?php

namespace AllClearly\Monitor;

use RuntimeException;

/** ปัญหาตอนส่ง event ส่งให้ onError เท่านั้น ไม่เคย throw ออกไปถึงแอป */
class MonitorException extends RuntimeException {}
