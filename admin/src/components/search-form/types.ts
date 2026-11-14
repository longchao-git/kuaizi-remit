export interface SearchFormOptionValue {
  label: string
  value: unknown
  disabled?: boolean
  children?: SearchFormOptionValue[]
}

export type SearchFormOptionType =
  | 'input'
  | 'twoInput'
  | 'select'
  | 'cascader'
  | 'date'
  | 'daterange'
  | 'selectInput'
  | 'radio'
  | 'custom'

interface SearchFormBaseOption {
  label: string
  key: string
  placeholder?: string
  col?: number
  xs?: number
  sm?: number
  md?: number
  lg?: number
  xl?: number
  labelWidth?: string
  isHidden?: boolean
  width?: string
}

export interface SearchFormInputOption extends SearchFormBaseOption {
  type: 'input' | 'custom'
}

export interface SearchFormDateOption extends SearchFormBaseOption {
  type: 'date' | 'daterange'
  valueFormat?: string
  format?: string
  defaultTime?: Date | [Date, Date]
}

export interface SearchFormSelectOption extends SearchFormBaseOption {
  type: 'select' | 'radio'
  options: SearchFormOptionValue[]
  filterable?: boolean
  multiple?: boolean
  collapseTags?: boolean
}

export interface SearchFormCascaderOption extends SearchFormBaseOption {
  type: 'cascader'
  options: SearchFormOptionValue[]
  filterable?: boolean
  props?: Record<string, unknown>
}

export interface SearchFormTwoInputOption extends SearchFormBaseOption {
  type: 'twoInput'
  keyOne: string
  keyTwo: string
}

export interface SearchFormSelectInputOption extends SearchFormBaseOption {
  type: 'selectInput'
  selKey: string
  options: SearchFormOptionValue[]
  filterable?: boolean
}

export type SearchFormOption =
  | SearchFormInputOption
  | SearchFormDateOption
  | SearchFormSelectOption
  | SearchFormCascaderOption
  | SearchFormTwoInputOption
  | SearchFormSelectInputOption
