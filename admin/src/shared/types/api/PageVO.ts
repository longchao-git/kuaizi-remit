/**
 * 后端分页对象（api/spring-boot: PageVO）
 *
 * 注意：后端没有 pages 字段，因此前端如需 pages 可自行计算。
 */
export interface PageVO<T> {
  records: T[]
  total: number
  size: number
  current: number
}
