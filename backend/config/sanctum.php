<?php

return [
    // 保留活动时间，由 TokenAuthenticated 监听器合并同一会话的高频写入。
    'last_used_at' => false,
];
