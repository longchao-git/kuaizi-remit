/**
 * MenuVO，菜单VO - 共享类型定义
 */
export interface MenuVO {
  /**
   * 子菜单
   */
  children?: MenuVO[] | null;
  /**
   * 组件路径
   */
  component?: string;
  /**
   * 创建人
   */
  createBy?: string;
  /**
   * 创建时间
   */
  createTime?: string;
  /**
   * 图标
   */
  icon?: string;
  /**
   * 主键ID
   */
  id?: string;
  /**
   * 菜单名称
   */
  name?: string;
  /**
   * 菜单英文名称
   */
  nameEn?: string;
  /**
   * 父级ID
   */
  parentId?: string;
  /**
   * 菜单路径
   */
  path?: string;
  /**
   * 菜单类型：MENU-菜单，BUTTON-按钮
   */
  menuType?: string;
  /**
   * 权限标识
   */
  permission?: string;
  /**
   * 排序
   */
  sort?: number;
  /**
   * 更新人
   */
  updateBy?: string;
  /**
   * 更新时间
   */
  updateTime?: string;
  /**
   * 是否有子菜单
   */
  hasChildren?: boolean;
  /**
   * 是否在侧边栏显示
   */
  visible?: boolean;
  /**
   * 备注（'隐藏页面'表示不在侧边栏显示）
   */
  remark?: string;
}
