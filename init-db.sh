#!/bin/bash

# ==============================================================================
# 独角数卡(dujiaoka)数据库初始化脚本
# 功能：快速导入 install.sql 到 MySQL 数据库
# 支持：命令行参数、.env配置文件、交互式密码输入、Docker容器执行
# ==============================================================================

set -e

# 版本信息
VERSION="1.0.0"
SCRIPT_NAME=$(basename "$0")

# 默认值（会被.env文件或命令行参数覆盖）
DEFAULT_DB_HOST="localhost"
DEFAULT_DB_PORT="3306"
DEFAULT_DB_NAME="dujiaoka"
DEFAULT_DB_USER="root"
DEFAULT_DB_PASS=""
DEFAULT_SQL_FILE="database/sql/install.sql"

# 使用Docker执行（如果本地没有mysql客户端）
USE_DOCKER=false
# 强制交互式密码输入
INTERACTIVE=false
# 静默模式（减少输出）
QUIET=false
# 跳过验证
SKIP_VERIFY=false

# 颜色输出
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# 日志函数
log_info() { echo -e "${BLUE}[INFO]${NC} $*"; }
log_success() { echo -e "${GREEN}[SUCCESS]${NC} $*"; }
log_warning() { echo -e "${YELLOW}[WARNING]${NC} $*"; }
log_error() { echo -e "${RED}[ERROR]${NC} $*" >&2; }
log_debug() { [[ "$QUIET" == false ]] && echo -e "[DEBUG] $*"; }

# 显示帮助信息
show_help() {
    cat << EOF
${SCRIPT_NAME} - 独角数卡数据库初始化工具 v${VERSION}

用法: ${SCRIPT_NAME} [选项]

选项:
  -h, --host HOST          数据库主机地址 (默认: ${DEFAULT_DB_HOST})
  -P, --port PORT          数据库端口 (默认: ${DEFAULT_DB_PORT})
  -u, --user USER          数据库用户名 (默认: ${DEFAULT_DB_USER})
  -p, --password PASS      数据库密码 (默认: 从.env读取或交互式输入)
  -d, --database DB        数据库名 (默认: ${DEFAULT_DB_NAME})
  -f, --file FILE          SQL文件路径 (默认: ${DEFAULT_SQL_FILE})

  --docker                 使用Docker容器执行（无需本地mysql客户端）
  --interactive            强制交互式密码输入（更安全）
  --skip-verify            跳过导入后的验证步骤
  --quiet                  静默模式（仅输出错误信息）

  --help                   显示此帮助信息
  --version                显示版本信息

示例:
  ${SCRIPT_NAME}                          # 使用.env配置自动初始化
  ${SCRIPT_NAME} -h 192.168.1.100 -u admin # 指定主机和用户
  ${SCRIPT_NAME} --docker                 # 使用Docker容器执行
  ${SCRIPT_NAME} --interactive            # 交互式输入密码

配置优先级:
  1. 命令行参数（最高）
  2. .env文件中的配置
  3. 脚本默认值（最低）

.env配置示例:
  DB_HOST=host.docker.internal
  DB_PORT=3306
  DB_DATABASE=dujiaoka
  DB_USERNAME=root
  DB_PASSWORD=your_password

EOF
}

# 显示版本信息
show_version() {
    echo "${SCRIPT_NAME} v${VERSION}"
    echo "独角数卡数据库初始化工具"
}

# 从.env文件读取配置
load_env_config() {
    local env_file=".env"

    if [[ ! -f "$env_file" ]]; then
        log_debug ".env文件不存在，跳过配置加载"
        return 1
    fi

    log_debug "从 ${env_file} 加载配置"

    # 使用grep提取配置，避免source可能的安全问题
    while IFS='=' read -r key value; do
        # 移除注释和空白
        key=$(echo "$key" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')
        value=$(echo "$value" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')

        case "$key" in
            DB_HOST)
                [[ -n "$value" && -z "$DB_HOST" ]] && DB_HOST="$value"
                ;;
            DB_PORT)
                [[ -n "$value" && -z "$DB_PORT" ]] && DB_PORT="$value"
                ;;
            DB_DATABASE)
                [[ -n "$value" && -z "$DB_NAME" ]] && DB_NAME="$value"
                ;;
            DB_USERNAME)
                [[ -n "$value" && -z "$DB_USER" ]] && DB_USER="$value"
                ;;
            DB_PASSWORD)
                [[ -n "$value" && -z "$DB_PASS" ]] && DB_PASS="$value"
                ;;
        esac
    done < <(grep -E "^(DB_HOST|DB_PORT|DB_DATABASE|DB_USERNAME|DB_PASSWORD)=" "$env_file" || true)

    log_debug "加载的配置: HOST=${DB_HOST:-未设置}, PORT=${DB_PORT:-未设置}, DB=${DB_NAME:-未设置}, USER=${DB_USER:-未设置}"
}

# 解析命令行参数
parse_args() {
    while [[ $# -gt 0 ]]; do
        case "$1" in
            -h|--host)
                DB_HOST="$2"
                shift 2
                ;;
            -P|--port)
                DB_PORT="$2"
                shift 2
                ;;
            -u|--user)
                DB_USER="$2"
                shift 2
                ;;
            -p|--password)
                DB_PASS="$2"
                shift 2
                ;;
            -d|--database)
                DB_NAME="$2"
                shift 2
                ;;
            -f|--file)
                SQL_FILE="$2"
                shift 2
                ;;
            --docker)
                USE_DOCKER=true
                shift
                ;;
            --interactive)
                INTERACTIVE=true
                shift
                ;;
            --skip-verify)
                SKIP_VERIFY=true
                shift
                ;;
            --quiet)
                QUIET=true
                shift
                ;;
            --help)
                show_help
                exit 0
                ;;
            --version)
                show_version
                exit 0
                ;;
            *)
                log_error "未知选项: $1"
                show_help
                exit 1
                ;;
        esac
    done
}

# 设置默认值
set_defaults() {
    DB_HOST="${DB_HOST:-$DEFAULT_DB_HOST}"
    DB_PORT="${DB_PORT:-$DEFAULT_DB_PORT}"
    DB_NAME="${DB_NAME:-$DEFAULT_DB_NAME}"
    DB_USER="${DB_USER:-$DEFAULT_DB_USER}"
    SQL_FILE="${SQL_FILE:-$DEFAULT_SQL_FILE}"
}

# 验证配置
validate_config() {
    local errors=()

    # 检查SQL文件
    if [[ ! -f "$SQL_FILE" ]]; then
        errors+=("SQL文件不存在: $SQL_FILE")
    fi

    # 检查必要参数
    [[ -z "$DB_HOST" ]] && errors+=("数据库主机未设置")
    [[ -z "$DB_PORT" ]] && errors+=("数据库端口未设置")
    [[ -z "$DB_NAME" ]] && errors+=("数据库名未设置")
    [[ -z "$DB_USER" ]] && errors+=("数据库用户未设置")

    # 检查端口是否为数字
    if ! [[ "$DB_PORT" =~ ^[0-9]+$ ]]; then
        errors+=("数据库端口必须是数字: $DB_PORT")
    fi

    # 如果有错误，显示并退出
    if [[ ${#errors[@]} -gt 0 ]]; then
        for error in "${errors[@]}"; do
            log_error "$error"
        done
        exit 1
    fi
}

# 安全获取密码
get_password() {
    if [[ -n "$DB_PASS" ]]; then
        log_debug "使用已设置的密码"
        return
    fi

    if [[ "$INTERACTIVE" == true ]]; then
        echo -n "请输入数据库密码: "
        read -rs DB_PASS
        echo
        [[ -z "$DB_PASS" ]] && log_warning "密码为空"
    else
        log_warning "密码未设置且未启用交互式模式"
        log_warning "将在命令行中传递空密码"
    fi
}

# 测试数据库连接
test_db_connection() {
    log_info "测试数据库连接..."

    local test_cmd
    if [[ "$USE_DOCKER" == true ]]; then
        test_cmd="docker run --rm mysql:8.0 mysql"
    else
        test_cmd="mysql"
    fi

    # 构建连接命令
    local connect_args=()
    connect_args+=("-h" "$DB_HOST")
    connect_args+=("-P" "$DB_PORT")
    connect_args+=("-u" "$DB_USER")

    # 处理密码（安全方式）
    if [[ -n "$DB_PASS" ]]; then
        if [[ "$USE_DOCKER" == true ]]; then
            # Docker环境使用环境变量
            local docker_cmd="MYSQL_PWD=\"$DB_PASS\" $test_cmd"
            eval "$docker_cmd ${connect_args[*]} -e \"SELECT 1;\"" > /dev/null 2>&1
        else
            # 本地使用--password选项
            "$test_cmd" "${connect_args[@]}" "--password=$DB_PASS" -e "SELECT 1;" > /dev/null 2>&1
        fi
    else
        "$test_cmd" "${connect_args[@]}" -e "SELECT 1;" > /dev/null 2>&1
    fi

    if [[ $? -eq 0 ]]; then
        log_success "数据库连接成功"
        return 0
    else
        log_error "数据库连接失败"
        log_error "请检查:"
        log_error "  1. 数据库服务是否运行"
        log_error "  2. 主机地址: $DB_HOST:$DB_PORT"
        log_error "  3. 用户名: $DB_USER"
        log_error "  4. 密码是否正确"
        log_error "  5. 网络连接是否正常"
        return 1
    fi
}

# 执行SQL导入
execute_import() {
    log_info "开始导入SQL文件: $SQL_FILE"

    if [[ "$USE_DOCKER" == true ]]; then
        log_debug "使用Docker容器执行导入"
        import_via_docker
    else
        log_debug "使用本地mysql客户端执行导入"
        import_via_local
    fi

    if [[ $? -eq 0 ]]; then
        log_success "SQL文件导入成功"
        return 0
    else
        log_error "SQL文件导入失败"
        return 1
    fi
}

# 通过Docker容器导入
import_via_docker() {
    local docker_args=()

    docker_args+=("--rm")
    docker_args+=("-v" "$(pwd)/$SQL_FILE:/install.sql:ro")

    if [[ -n "$DB_PASS" ]]; then
        docker_args+=("-e" "MYSQL_PWD=$DB_PASS")
    fi

    docker_args+=("mysql:8.0")

    # 执行导入
    docker run "${docker_args[@]}" \
        mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" "$DB_NAME" < "/install.sql"
}

# 通过本地mysql客户端导入
import_via_local() {
    local mysql_args=()

    mysql_args+=("-h" "$DB_HOST")
    mysql_args+=("-P" "$DB_PORT")
    mysql_args+=("-u" "$DB_USER")

    # 处理密码
    if [[ -n "$DB_PASS" ]]; then
        mysql_args+=("--password=$DB_PASS")
    fi

    mysql_args+=("$DB_NAME")

    # 执行导入
    mysql "${mysql_args[@]}" < "$SQL_FILE"
}

# 验证导入结果
verify_import() {
    [[ "$SKIP_VERIFY" == true ]] && return 0

    log_info "验证导入结果..."

    local verify_cmd
    if [[ "$USE_DOCKER" == true ]]; then
        verify_cmd="docker run --rm mysql:8.0 mysql"
    else
        verify_cmd="mysql"
    fi

    # 构建验证命令
    local connect_args=()
    connect_args+=("-h" "$DB_HOST")
    connect_args+=("-P" "$DB_PORT")
    connect_args+=("-u" "$DB_USER")

    # 检查表数量
    local table_count=0
    if [[ -n "$DB_PASS" ]]; then
        if [[ "$USE_DOCKER" == true ]]; then
            table_count=$(MYSQL_PWD="$DB_PASS" $verify_cmd "${connect_args[@]}" "$DB_NAME" -e "SHOW TABLES;" 2>/dev/null | wc -l)
        else
            table_count=$("$verify_cmd" "${connect_args[@]}" "--password=$DB_PASS" "$DB_NAME" -e "SHOW TABLES;" 2>/dev/null | wc -l)
        fi
    else
        table_count=$("$verify_cmd" "${connect_args[@]}" "$DB_NAME" -e "SHOW TABLES;" 2>/dev/null | wc -l)
    fi

    # 减去标题行
    table_count=$((table_count - 1))

    if [[ $table_count -gt 0 ]]; then
        log_success "验证成功: 数据库中有 $table_count 张表"

        # 检查admin_menu表
        local menu_count=0
        if [[ -n "$DB_PASS" ]]; then
            if [[ "$USE_DOCKER" == true ]]; then
                menu_count=$(MYSQL_PWD="$DB_PASS" $verify_cmd "${connect_args[@]}" "$DB_NAME" -e "SELECT COUNT(*) FROM admin_menu;" 2>/dev/null | tail -1)
            else
                menu_count=$("$verify_cmd" "${connect_args[@]}" "--password=$DB_PASS" "$DB_NAME" -e "SELECT COUNT(*) FROM admin_menu;" 2>/dev/null | tail -1)
            fi
        else
            menu_count=$("$verify_cmd" "${connect_args[@]}" "$DB_NAME" -e "SELECT COUNT(*) FROM admin_menu;" 2>/dev/null | tail -1)
        fi

        log_success "admin_menu表中有 $menu_count 条记录"
        return 0
    else
        log_error "验证失败: 数据库中没有表或连接失败"
        return 1
    fi
}

# 显示配置摘要
show_summary() {
    cat << EOF

==============================================================================
数据库初始化配置摘要
==============================================================================
数据库主机:   ${DB_HOST}
数据库端口:   ${DB_PORT}
数据库名:     ${DB_NAME}
用户名:       ${DB_USER}
SQL文件:      ${SQL_FILE}
执行方式:     $([[ "$USE_DOCKER" == true ]] && echo "Docker容器" || echo "本地客户端")
交互模式:     $([[ "$INTERACTIVE" == true ]] && echo "是" || echo "否")
跳过验证:     $([[ "$SKIP_VERIFY" == true ]] && echo "是" || echo "否")
==============================================================================

EOF
}

# 主函数
main() {
    log_info "独角数卡数据库初始化工具 v${VERSION}"

    # 1. 解析命令行参数
    parse_args "$@"

    # 2. 从.env文件加载配置
    load_env_config

    # 3. 设置默认值
    set_defaults

    # 4. 显示配置摘要
    show_summary

    # 5. 验证配置
    validate_config

    # 6. 获取密码
    get_password

    # 7. 测试数据库连接
    test_db_connection || exit 1

    # 8. 执行SQL导入
    execute_import || exit 1

    # 9. 验证导入结果
    verify_import || {
        log_warning "验证失败，但SQL已导入"
        log_warning "请手动检查数据库状态"
    }

    # 10. 完成
    log_success "数据库初始化完成！"
    log_success "应用访问地址: http://localhost:9595 (根据实际配置调整)"
    log_success "后台管理地址: http://localhost:9595/admin"
    log_success "默认账号: admin / admin (请及时修改)"

    exit 0
}

# 脚本入口
if [[ "${BASH_SOURCE[0]}" == "${0}" ]]; then
    main "$@"
fi