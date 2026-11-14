'use strict';

const { timingSafeEqual } = require('crypto');

function respond(statusCode, accepted = false) {
  return {
    mpserverlessComposedResponse: true,
    isBase64Encoded: false,
    statusCode,
    headers: { 'content-type': 'application/json; charset=utf-8', 'cache-control': 'no-store' },
    body: JSON.stringify({ accepted }),
  };
}

exports.main = async (event) => {
  if (event.httpMethod !== 'POST') return respond(405);
  const appId = process.env.REMIT_PUSH_APP_ID;
  const secret = process.env.REMIT_PUSH_BRIDGE_SECRET;
  if (!/^__UNI__[A-Za-z0-9]+$/.test(appId || '') || !/^[A-Za-z0-9_-]{32,128}$/.test(secret || '')) return respond(503);

  const headers = Object.entries(event.headers || {});
  const header = headers.find(([name]) => name.toLowerCase() === 'authorization')?.[1];
  const received = Buffer.from(typeof header === 'string' && header.length < 140 ? header : '');
  const expected = Buffer.from('Bearer ' + secret);
  if (received.length !== expected.length || !timingSafeEqual(received, expected)) return respond(403);
  if (typeof event.body !== 'string' || event.body.length > 2048) return respond(400);

  let payload;
  try {
    payload = JSON.parse(event.isBase64Encoded ? Buffer.from(event.body, 'base64').toString('utf8') : event.body);
  } catch {
    return respond(400);
  }
  const { cid, notice_id, request_id } = payload || {};
  if (typeof cid !== 'string' || !/^[A-Za-z0-9_-]{8,128}$/.test(cid) ||
      typeof notice_id !== 'string' || !/^[1-9][0-9]{0,17}$/.test(notice_id) ||
      typeof request_id !== 'string' || !/^[0-9a-f]{32}$/.test(request_id)) return respond(400);

  try {
    const result = await uniCloud.getPushManager({ appId }).sendMessage({
      push_clientid: cid,
      request_id,
      title: '筷子闪汇',
      content: '您有一条新消息',
      payload: { kind: 'member_notice', notice_id },
      force_notification: true,
      settings: { ttl: 3600000 },
    });
    return result?.errCode === 0 ? respond(200, true) : respond(502);
  } catch {
    return respond(502);
  }
};
