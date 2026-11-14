export interface BaseTableTagMapItem {
  label: string
  type?: '' | 'success' | 'warning' | 'info' | 'primary' | 'danger'
}

export interface BaseTableAction {
  key?: string
  label: string
  onClick: (row: Record<string, any>, index: number) => void | Promise<void>
  hidden?: boolean | ((row: Record<string, any>, index: number) => boolean)
  disabled?: boolean | ((row: Record<string, any>, index: number) => boolean)
  permission?: string | string[]
  permissionMode?: 'any' | 'all'
  type?: 'primary' | 'danger' | 'success' | 'warning' | 'info'
}

export interface BaseTableColumn {
  key: string
  label: string
  prop?: string
  type?: 'text' | 'tag' | 'datetime' | 'image' | 'custom' | 'actions'
  width?: string | number
  minWidth?: string | number
  fixed?: true | 'left' | 'right'
  align?: 'left' | 'center' | 'right'
  headerAlign?: 'left' | 'center' | 'right'
  showOverflowTooltip?: boolean
  formatter?: (row: Record<string, any>, index: number) => unknown
  emptyText?: string
  datetimeFormat?: string
  tagType?: BaseTableTagMapItem['type'] | ((value: unknown, row: Record<string, any>, index: number) => BaseTableTagMapItem['type'])
  tagLabel?: (value: unknown, row: Record<string, any>, index: number) => string
  tagMap?: Record<string, string | BaseTableTagMapItem>
  slot?: string
  headerSlot?: string
  headerTips?: boolean
  imageWidth?: string
  imageHeight?: string
  imageFit?: 'fill' | 'contain' | 'cover' | 'none' | 'scale-down'
  imagePreview?: boolean
  actions?: BaseTableAction[]
  primaryActionCount?: number
  moreActionText?: string
}

export interface BaseTableProps {
  data?: Record<string, any>[]
  columns?: BaseTableColumn[]
  rowKey?: string | ((row: Record<string, any>) => string)
  headerCellStyle?: import('vue').CSSProperties
  emptyText?: string
  multiple?: boolean
  reserveSelection?: boolean
  selectionColumnWidth?: string | number
  selectedRowKeys?: Array<string | number>
  selectionKey?: string
  rowDraggable?: boolean
}
