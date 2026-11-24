export interface RemittanceDeclaration {
  source_of_funds: string;
  purpose: string;
  explanation: string;
}

export const fundSources = [
  { value: '01', label: '工资收入' }, { value: '02', label: '储蓄' },
  { value: '03', label: '经营收入' }, { value: '04', label: '兼职收入' },
];
export const transferPurposes = [
  { value: '01', label: '家庭支持' }, { value: '02', label: '账单支付' },
  { value: '03', label: '储蓄' }, { value: '04', label: '购买商品' }, { value: '05', label: '其他' },
];
export const emptyDeclaration = (): RemittanceDeclaration => ({ source_of_funds: '', purpose: '', explanation: '' });

export function declarationError(value: RemittanceDeclaration): string {
  if (!fundSources.some(item => item.value === value.source_of_funds)) return '请选择资金来源';
  if (!transferPurposes.some(item => item.value === value.purpose)) return '请选择汇款用途';
  if (value.purpose === '05' && !value.explanation.trim()) return '请补充具体汇款用途';
  return '';
}
