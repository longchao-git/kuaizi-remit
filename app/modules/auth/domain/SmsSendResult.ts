/** 发送验证码结果；test_code 仅在本地测试环境返回。 */
export interface SmsSendResult {
  sent: boolean;
  test_code?: string;
}
