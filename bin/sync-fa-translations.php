#!/usr/bin/env php
<?php
/**
 * Deterministically fill new Persian catalog entries for the current release.
 *
 * This development helper is excluded from release archives.
 *
 * @package FlatsomeMCP
 */

declare(strict_types=1);

$path   = dirname( __DIR__ ) . '/languages/mindio-magic-mcp-fa_IR.po';
$source = file_get_contents( $path );
if ( false === $source ) {
	fwrite( STDERR, "Could not read Persian catalog.\n" );
	exit( 1 );
}

/** Decode a PO quoted value plus continuation lines. */
function fmp_fa_po_field( string $entry, string $field ): ?string {
	$lines   = explode( "\n", str_replace( "\r\n", "\n", $entry ) );
	$current = false;
	$value   = '';
	foreach ( $lines as $line ) {
		if ( preg_match( '/^' . preg_quote( $field, '/' ) . '\s+(".*")$/', $line, $matches ) ) {
			$decoded = json_decode( $matches[1], true );
			if ( ! is_string( $decoded ) ) {
				return null;
			}
			$value   = $decoded;
			$current = true;
			continue;
		}
		if ( $current && preg_match( '/^(".*")$/', $line, $matches ) ) {
			$decoded = json_decode( $matches[1], true );
			if ( ! is_string( $decoded ) ) {
				return null;
			}
			$value .= $decoded;
			continue;
		}
		if ( $current ) {
			break;
		}
	}
	return $current ? $value : null;
}

/** @return string|array{0:string,1:string}|null */
function fmp_fa_translation( string $msgid, ?string $plural ): string|array|null {
	$translations = array(
		'ACF field not found.'                                                                                                                   => 'فیلد ACF پیدا نشد.',
		'Enabled'                                                                                                                              => 'فعال',
		'%1$d of %2$d operations enabled'                                                                                                      => '%1$d از %2$d عملیات فعال است',
		'Tool policy updated: %1$s tools exposed, %2$s tools disabled; %3$s operations enabled, %4$s operations disabled.'                    => 'سیاست ابزار به‌روزرسانی شد: %1$s ابزار در دسترس، %2$s ابزار غیرفعال؛ %3$s عملیات فعال و %4$s عملیات غیرفعال است.',
		'Enabled operations'                                                                                                                    => 'عملیات فعال',
		'Granular integration access'                                                                                                           => 'دسترسی جزئی به یکپارچه‌سازی‌ها',
		'Disabled operations'                                                                                                                   => 'عملیات غیرفعال',
		'Write operations start disabled'                                                                                                       => 'عملیات نوشتن در ابتدا غیرفعال است',
		'Disabled tools are removed from discovery and rejected when called directly. Expand integration tools to govern each read or write operation independently. Credentials and permission scopes are not changed.' => 'ابزارهای غیرفعال از فهرست کشف حذف می‌شوند و فراخوانی مستقیم آن‌ها رد می‌شود. ابزارهای یکپارچه‌سازی را باز کنید تا هر عملیات خواندن یا نوشتن را مستقل مدیریت کنید. اطلاعات ورود و دامنه‌های دسترسی تغییر نمی‌کنند.',
		'%s operation'                                                                                                                          => array( 'عملیات %s', 'عملیات %s' ),
		'Expose %s tool'                                                                                                                        => 'در دسترس قرار دادن ابزار %s',
		'Manage'                                                                                                                               => 'مدیریت',
		'Operation policy'                                                                                                                     => 'سیاست عملیات',
		'Enable reads'                                                                                                                         => 'فعال‌کردن خواندن‌ها',
		'Disable writes'                                                                                                                       => 'غیرفعال‌کردن نوشتن‌ها',
		'Read'                                                                                                                                 => 'خواندن',
		'Write'                                                                                                                                => 'نوشتن',
		'Tool and operation policy changes apply to new MCP requests immediately.'                                                             => 'تغییرات سیاست ابزار و عملیات بلافاصله روی درخواست‌های جدید MCP اعمال می‌شود.',
		'A disabled operation remains unavailable even when its parent integration tool is exposed.'                                          => 'عملیات غیرفعال حتی در صورت در دسترس بودن ابزار یکپارچه‌سازی والد، همچنان در دسترس نخواهد بود.',
		'Allow bounded text-file reads, directory listings, and searches inside approved WordPress content roots.'                            => 'خواندن محدود فایل متنی، فهرست‌کردن پوشه و جست‌وجو در ریشه‌های محتوای تأییدشده وردپرس مجاز شود.',
		'Read-only filesystem inspection'                                                                                                       => 'بررسی فقط‌خواندنی فایل‌سیستم',
		'Gutenberg blocks'                                                                                                                      => 'بلوک‌های گوتنبرگ',
		'Structured block discovery, tree editing, duplication, movement, and patterns.'                                                       => 'کشف ساختاریافته بلوک، ویرایش درخت، تکثیر، جابه‌جایی و الگوها.',
		'Official-directory package lifecycle, generic theme controls, and child themes.'                                                     => 'چرخه عمر بسته‌های مخزن رسمی، کنترل‌های عمومی پوسته و پوسته‌های فرزند.',
		'Plugin integrations'                                                                                                                  => 'یکپارچه‌سازی افزونه‌ها',
		'Operation-level controls for ACF Free and Contact Form 7.'                                                                            => 'کنترل در سطح عملیات برای ACF Free و Contact Form 7.',
		'Read-only filesystem and database inspection, logs, maintenance, caches, CDN, and image optimization.'                               => 'بررسی فقط‌خواندنی فایل‌سیستم و پایگاه‌داده، گزارش‌ها، نگه‌داری، کش‌ها، CDN و بهینه‌سازی تصویر.',
		'The requested block position is invalid.'                                                                                              => 'موقعیت درخواستی بلوک معتبر نیست.',
		'At least one parsed block is required.'                                                                                                => 'حداقل یک بلوک تجزیه‌شده لازم است.',
		'The block path no longer resolves. Read the current block tree and try again.'                                                        => 'مسیر بلوک دیگر قابل شناسایی نیست. درخت فعلی بلوک را بخوانید و دوباره تلاش کنید.',
		'A block cannot be moved into itself or one of its descendants.'                                                                       => 'بلوک را نمی‌توان به داخل خودش یا یکی از زیرمجموعه‌هایش منتقل کرد.',
		'The source block path no longer resolves.'                                                                                             => 'مسیر بلوک مبدأ دیگر قابل شناسایی نیست.',
		'The block path no longer resolves.'                                                                                                    => 'مسیر بلوک دیگر قابل شناسایی نیست.',
		'The block tree exceeds the maximum number of blocks.'                                                                                 => 'تعداد بلوک‌های درخت از حداکثر مجاز بیشتر است.',
		'The block tree exceeds the maximum nesting depth.'                                                                                    => 'عمق تودرتویی درخت بلوک از حداکثر مجاز بیشتر است.',
		'Raw HTML must be wrapped in an explicit Gutenberg block.'                                                                             => 'HTML خام باید در یک بلوک صریح گوتنبرگ قرار گیرد.',
		'The Gutenberg block type %s is not registered on this site.'                                                                          => 'نوع بلوک گوتنبرگ %s در این سایت ثبت نشده است.',
		'Run an enabled read operation for the %s integration, or omit operation to inspect its available read capabilities.'                  => 'یک عملیات خواندن فعال برای یکپارچه‌سازی %s اجرا کنید، یا برای مشاهده قابلیت‌های خواندن موجود، operation را وارد نکنید.',
		'Run an administrator-enabled write operation for the %s integration. Write operations are disabled by default.'                      => 'یک عملیات نوشتن فعال‌شده توسط مدیر برای یکپارچه‌سازی %s اجرا کنید. عملیات نوشتن به‌طور پیش‌فرض غیرفعال است.',
		'Enabled integration operation to execute. Omit it to return the operation catalog.'                                                   => 'عملیات فعال یکپارچه‌سازی برای اجرا. برای دریافت فهرست عملیات، آن را وارد نکنید.',
		'Arguments validated against the selected operation schema.'                                                                           => 'آرگومان‌ها بر اساس شِمای عملیات انتخاب‌شده اعتبارسنجی می‌شوند.',
		'%s is not installed and active.'                                                                                                       => '%s نصب و فعال نیست.',
		'Unknown integration operation.'                                                                                                        => 'عملیات یکپارچه‌سازی ناشناخته است.',
		'The access token does not grant this integration operation scope.'                                                                    => 'توکن دسترسی، دامنه لازم برای این عملیات یکپارچه‌سازی را اعطا نمی‌کند.',
		'Your WordPress user cannot perform this integration operation.'                                                                       => 'کاربر وردپرس شما اجازه انجام این عملیات یکپارچه‌سازی را ندارد.',
		'The integration operation is not callable.'                                                                                            => 'این عملیات یکپارچه‌سازی قابل فراخوانی نیست.',
		'This integration operation is disabled by the site administrator.'                                                                    => 'این عملیات یکپارچه‌سازی توسط مدیر سایت غیرفعال شده است.',
		'List field groups'                                                                                                                     => 'فهرست گروه‌های فیلد',
		'List ACF field groups and their field summaries.'                                                                                     => 'فهرست گروه‌های فیلد ACF و خلاصه فیلدهای آن‌ها.',
		'Get field group'                                                                                                                       => 'دریافت گروه فیلد',
		'Get one ACF field group and its complete free-field definitions.'                                                                     => 'دریافت یک گروه فیلد ACF و تعریف کامل فیلدهای رایگان آن.',
		'Get field value'                                                                                                                       => 'دریافت مقدار فیلد',
		'Read one ACF value from a post using a field name or key.'                                                                             => 'خواندن یک مقدار ACF از نوشته با استفاده از نام یا کلید فیلد.',
		'List ACF post types'                                                                                                                   => 'فهرست نوع‌نوشته‌های ACF',
		'List custom post types registered through the ACF Free UI.'                                                                           => 'فهرست نوع‌نوشته‌های سفارشی ثبت‌شده از رابط ACF Free.',
		'List ACF taxonomies'                                                                                                                   => 'فهرست رده‌بندی‌های ACF',
		'List taxonomies registered through the ACF Free UI.'                                                                                  => 'فهرست رده‌بندی‌های ثبت‌شده از رابط ACF Free.',
		'Create or update field group'                                                                                                          => 'ایجاد یا به‌روزرسانی گروه فیلد',
		'Create or update a sanitized ACF field group. Pro-only structures are not accepted.'                                                  => 'ایجاد یا به‌روزرسانی گروه فیلد پاک‌سازی‌شده ACF. ساختارهای مخصوص نسخه Pro پذیرفته نمی‌شوند.',
		'Delete field group'                                                                                                                    => 'حذف گروه فیلد',
		'Permanently delete an ACF field group after explicit confirmation.'                                                                   => 'حذف دائمی گروه فیلد ACF پس از تأیید صریح.',
		'Create or update field'                                                                                                                => 'ایجاد یا به‌روزرسانی فیلد',
		'Create or update a native ACF Free field within a field group.'                                                                        => 'ایجاد یا به‌روزرسانی یک فیلد بومی ACF Free درون گروه فیلد.',
		'Delete field'                                                                                                                          => 'حذف فیلد',
		'Permanently delete an ACF field after explicit confirmation.'                                                                         => 'حذف دائمی فیلد ACF پس از تأیید صریح.',
		'Update field value'                                                                                                                    => 'به‌روزرسانی مقدار فیلد',
		'Update one ACF value on a post using a field name or key.'                                                                             => 'به‌روزرسانی یک مقدار ACF روی نوشته با استفاده از نام یا کلید فیلد.',
		'Create or update ACF post type'                                                                                                        => 'ایجاد یا به‌روزرسانی نوع‌نوشته ACF',
		'Create or update a custom post type using the ACF Free post-type UI storage.'                                                         => 'ایجاد یا به‌روزرسانی نوع‌نوشته سفارشی با استفاده از ذخیره‌سازی رابط نوع‌نوشته ACF Free.',
		'Delete ACF post type'                                                                                                                  => 'حذف نوع‌نوشته ACF',
		'Delete an ACF-managed post-type registration, without deleting its content.'                                                         => 'حذف ثبت نوع‌نوشته مدیریت‌شده با ACF، بدون حذف محتوای آن.',
		'Create or update ACF taxonomy'                                                                                                         => 'ایجاد یا به‌روزرسانی رده‌بندی ACF',
		'Create or update a taxonomy using the ACF Free taxonomy UI storage.'                                                                   => 'ایجاد یا به‌روزرسانی رده‌بندی با استفاده از ذخیره‌سازی رابط رده‌بندی ACF Free.',
		'Delete ACF taxonomy'                                                                                                                   => 'حذف رده‌بندی ACF',
		'Delete an ACF-managed taxonomy registration, without deleting its terms.'                                                             => 'حذف ثبت رده‌بندی مدیریت‌شده با ACF، بدون حذف عبارت‌های آن.',
		'The ACF field group was not found.'                                                                                                    => 'گروه فیلد ACF پیدا نشد.',
		'ACF could not save the field group.'                                                                                                   => 'ACF نتوانست گروه فیلد را ذخیره کند.',
		'ACF could not delete the field group.'                                                                                                 => 'ACF نتوانست گروه فیلد را حذف کند.',
		'ACF could not save the field.'                                                                                                         => 'ACF نتوانست فیلد را ذخیره کند.',
		'ACF could not delete the field.'                                                                                                       => 'ACF نتوانست فیلد را حذف کند.',
		'ACF could not update the field value.'                                                                                                 => 'ACF نتوانست مقدار فیلد را به‌روزرسانی کند.',
		'ACF could not save the post type.'                                                                                                     => 'ACF نتوانست نوع‌نوشته را ذخیره کند.',
		'ACF could not delete the post-type registration.'                                                                                     => 'ACF نتوانست ثبت نوع‌نوشته را حذف کند.',
		'ACF could not save the taxonomy.'                                                                                                      => 'ACF نتوانست رده‌بندی را ذخیره کند.',
		'ACF could not delete the taxonomy registration.'                                                                                       => 'ACF نتوانست ثبت رده‌بندی را حذف کند.',
		'Field group title is required.'                                                                                                        => 'عنوان گروه فیلد الزامی است.',
		'Field name, label, parent, and a native ACF Free field type are required.'                                                            => 'نام، برچسب، والد و یک نوع فیلد بومی ACF Free الزامی است.',
		'A title and a valid post type key of at most 20 characters are required.'                                                             => 'عنوان و کلید معتبر نوع‌نوشته با حداکثر ۲۰ نویسه الزامی است.',
		'A title, valid taxonomy key, and at least one existing object type are required.'                                                      => 'عنوان، کلید معتبر رده‌بندی و حداقل یک نوع شیء موجود الزامی است.',
		'This ACF structure operation requires confirm=true.'                                                                                  => 'این عملیات ساختاری ACF به confirm=true نیاز دارد.',
		'This operation requires ACF Free 6.1 or newer.'                                                                                        => 'این عملیات به ACF Free نسخه 6.1 یا جدیدتر نیاز دارد.',
		'List contact forms'                                                                                                                    => 'فهرست فرم‌های تماس',
		'List Contact Form 7 forms with shortcode and modification metadata.'                                                                  => 'فهرست فرم‌های Contact Form 7 همراه با کدکوتاه و فراداده تغییرات.',
		'Get contact form'                                                                                                                      => 'دریافت فرم تماس',
		'Get one Contact Form 7 form, mail templates, messages, and additional settings.'                                                      => 'دریافت یک فرم Contact Form 7، قالب‌های ایمیل، پیام‌ها و تنظیمات اضافی.',
		'Create contact form'                                                                                                                   => 'ایجاد فرم تماس',
		'Create a Contact Form 7 form from its default template and sanitized property overrides.'                                            => 'ایجاد فرم Contact Form 7 از قالب پیش‌فرض آن و مقادیر جایگزین پاک‌سازی‌شده ویژگی‌ها.',
		'Update contact form'                                                                                                                   => 'به‌روزرسانی فرم تماس',
		'Update a Contact Form 7 title or property set with optional optimistic concurrency.'                                                 => 'به‌روزرسانی عنوان یا مجموعه ویژگی‌های Contact Form 7 با کنترل هم‌زمانی خوش‌بینانه اختیاری.',
		'Duplicate contact form'                                                                                                                => 'تکثیر فرم تماس',
		'Duplicate a Contact Form 7 form and optionally assign a new title.'                                                                   => 'تکثیر فرم Contact Form 7 و اختصاص اختیاری عنوان جدید.',
		'Delete contact form'                                                                                                                   => 'حذف فرم تماس',
		'Permanently delete a Contact Form 7 form after explicit confirmation.'                                                               => 'حذف دائمی فرم Contact Form 7 پس از تأیید صریح.',
		'Submit contact form'                                                                                                                   => 'ارسال فرم تماس',
		'Submit non-file fields through Contact Form 7 validation and delivery. This can send external email and requires confirmation.'      => 'ارسال فیلدهای غیرفایلی از مسیر اعتبارسنجی و تحویل Contact Form 7. این کار می‌تواند ایمیل خارجی ارسال کند و به تأیید نیاز دارد.',
		'Contact Form 7 does not accept the requested locale.'                                                                                 => 'Contact Form 7 زبان درخواستی را نمی‌پذیرد.',
		'Contact Form 7 could not create the form.'                                                                                             => 'Contact Form 7 نتوانست فرم را ایجاد کند.',
		'Contact Form 7 could not update the form.'                                                                                             => 'Contact Form 7 نتوانست فرم را به‌روزرسانی کند.',
		'Contact Form 7 could not duplicate the form.'                                                                                          => 'Contact Form 7 نتوانست فرم را تکثیر کند.',
		'Contact Form 7 could not delete the form.'                                                                                             => 'Contact Form 7 نتوانست فرم را حذف کند.',
		'Form submission can send external email and requires confirm=true.'                                                                   => 'ارسال فرم می‌تواند ایمیل خارجی بفرستد و به confirm=true نیاز دارد.',
		'A form submission may contain at most 100 fields.'                                                                                    => 'ارسال فرم می‌تواند حداکثر ۱۰۰ فیلد داشته باشد.',
		'Form field names must be safe and cannot override Contact Form 7 control fields.'                                                     => 'نام فیلدهای فرم باید امن باشد و نمی‌تواند فیلدهای کنترلی Contact Form 7 را بازنویسی کند.',
		'Form field values must be strings or arrays of strings.'                                                                              => 'مقادیر فیلد فرم باید رشته یا آرایه‌ای از رشته‌ها باشند.',
		'Contact Form 7 form not found.'                                                                                                        => 'فرم Contact Form 7 پیدا نشد.',
		'Only form, mail, mail_2, messages, and additional_settings may be updated.'                                                           => 'فقط form، mail، mail_2، messages و additional_settings قابل به‌روزرسانی هستند.',
		'The contact form template is unsafe or too large.'                                                                                    => 'قالب فرم تماس ناامن یا بیش از حد بزرگ است.',
		'The form changed since it was read. Fetch it again before updating.'                                                                  => 'فرم پس از خوانده‌شدن تغییر کرده است. پیش از به‌روزرسانی دوباره آن را دریافت کنید.',
		'Contact form structure changes require confirm=true.'                                                                                 => 'تغییر ساختار فرم تماس به confirm=true نیاز دارد.',
		'List non-sensitive tables for the current WordPress site with bounded storage metadata. Requires read-only SQL to be enabled.'       => 'فهرست جدول‌های غیرحساس سایت فعلی وردپرس با فراداده محدود ذخیره‌سازی. مستلزم فعال‌بودن SQL فقط‌خواندنی است.',
		'Describe columns and indexes for one non-sensitive current-site table. No table rows are returned.'                                   => 'شرح ستون‌ها و نمایه‌های یک جدول غیرحساس سایت فعلی. هیچ ردیفی از جدول برگردانده نمی‌شود.',
		'The requested table is missing or classified as sensitive.'                                                                          => 'جدول درخواستی وجود ندارد یا حساس طبقه‌بندی شده است.',
		'Server schemas, identities, variables, and version metadata cannot be queried.'                                                       => 'شِماها، هویت‌ها، متغیرها و فراداده نسخه سرور قابل پرس‌وجو نیستند.',
		'Use the table-list tool; raw SHOW is limited to columns and indexes of safe tables.'                                                   => 'از ابزار فهرست جدول استفاده کنید؛ SHOW خام فقط برای ستون‌ها و نمایه‌های جدول‌های امن مجاز است.',
		'Derived-table subqueries are not supported by the safe SQL validator.'                                                                => 'زیرپرس‌وجوهای جدول مشتق‌شده توسط اعتبارسنج امن SQL پشتیبانی نمی‌شوند.',
		'Safe SELECT and EXPLAIN queries must reference an allowed current-site table.'                                                        => 'پرس‌وجوهای امن SELECT و EXPLAIN باید به یک جدول مجاز در سایت فعلی ارجاع دهند.',
		'The query references a missing, cross-site, or sensitive table.'                                                                      => 'پرس‌وجو به جدولی ناموجود، متعلق به سایت دیگر یا حساس ارجاع می‌دهد.',
		'Raw postmeta values may contain credentials and cannot be selected.'                                                                  => 'مقادیر خام postmeta ممکن است حاوی اطلاعات ورود باشند و قابل انتخاب نیستند.',
		'Search the WordPress.org plugin directory without installing anything.'                                                              => 'جست‌وجوی مخزن افزونه WordPress.org بدون نصب چیزی.',
		'Update one installed plugin to its latest available version. Requires confirm=true.'                                                 => 'به‌روزرسانی یک افزونه نصب‌شده به جدیدترین نسخه موجود. به confirm=true نیاز دارد.',
		'Search the WordPress.org theme directory without installing anything.'                                                               => 'جست‌وجوی مخزن پوسته WordPress.org بدون نصب چیزی.',
		'Install a theme by exact WordPress.org slug. Custom package URLs are never accepted. Requires confirm=true.'                          => 'نصب پوسته با نامک دقیق WordPress.org. نشانی بسته سفارشی هرگز پذیرفته نمی‌شود. به confirm=true نیاز دارد.',
		'Update one installed theme to its latest available version. Requires confirm=true.'                                                  => 'به‌روزرسانی یک پوسته نصب‌شده به جدیدترین نسخه موجود. به confirm=true نیاز دارد.',
		'Permanently delete an inactive theme. Active themes and their parent themes are protected. Requires confirm=true.'                    => 'حذف دائمی پوسته غیرفعال. پوسته فعال و پوسته والد آن محافظت می‌شوند. به confirm=true نیاز دارد.',
		'Plugin updates require confirm=true.'                                                                                                  => 'به‌روزرسانی افزونه به confirm=true نیاز دارد.',
		'Plugin update failed.'                                                                                                                 => 'به‌روزرسانی افزونه ناموفق بود.',
		'Theme installation requires confirm=true.'                                                                                             => 'نصب پوسته به confirm=true نیاز دارد.',
		'The WordPress.org theme slug is invalid.'                                                                                              => 'نامک پوسته WordPress.org معتبر نیست.',
		'Theme installation failed.'                                                                                                            => 'نصب پوسته ناموفق بود.',
		'The theme installed, but your user cannot activate it.'                                                                                => 'پوسته نصب شد، اما کاربر شما اجازه فعال‌سازی آن را ندارد.',
		'Theme updates require confirm=true.'                                                                                                   => 'به‌روزرسانی پوسته به confirm=true نیاز دارد.',
		'Theme update failed.'                                                                                                                  => 'به‌روزرسانی پوسته ناموفق بود.',
		'Theme deletion requires confirm=true.'                                                                                                 => 'حذف پوسته به confirm=true نیاز دارد.',
		'The active theme and its parent theme cannot be deleted.'                                                                              => 'پوسته فعال و پوسته والد آن قابل حذف نیستند.',
		'The theme stylesheet slug is invalid.'                                                                                                 => 'نامک شیوه‌نامه پوسته معتبر نیست.',
		'This site requires interactive filesystem credentials, so MCP cannot safely perform the package operation.'                         => 'این سایت به اطلاعات ورود تعاملی فایل‌سیستم نیاز دارد؛ بنابراین MCP نمی‌تواند عملیات بسته را با ایمنی انجام دهد.',
		'WordPress could not initialize direct filesystem access.'                                                                              => 'وردپرس نتوانست دسترسی مستقیم فایل‌سیستم را راه‌اندازی کند.',
		'The WordPress.org API returned an untrusted package URL.'                                                                              => 'API وردپرس‌دات‌ارگ نشانی بسته نامعتبری برگرداند.',
		'Read a bounded UTF-8 text file from an allowlisted WordPress content root. Sensitive files and common secret assignments are redacted.' => 'خواندن محدود یک فایل متنی UTF-8 از ریشه محتوای وردپرس در فهرست مجاز. فایل‌های حساس و انتساب‌های رایج اسرار پوشانده می‌شوند.',
		'List a bounded directory tree inside an allowlisted WordPress content root without following symbolic links.'                         => 'فهرست محدود درخت پوشه در ریشه محتوای وردپرسِ مجاز، بدون دنبال‌کردن پیوندهای نمادین.',
		'Search bounded UTF-8 text files for a literal string inside an allowlisted WordPress content root.'                                   => 'جست‌وجوی یک رشته دقیق در فایل‌های متنی UTF-8 محدود، داخل ریشه محتوای وردپرسِ مجاز.',
		'Readable files are limited to 256 KB.'                                                                                                  => 'اندازه فایل‌های قابل خواندن به ۲۵۶ کیلوبایت محدود است.',
		'The selected file is not readable UTF-8 text.'                                                                                          => 'فایل انتخاب‌شده متن UTF-8 قابل خواندن نیست.',
		'No requested file extensions are in the text-file allowlist.'                                                                          => 'هیچ‌یک از پسوندهای درخواستی در فهرست مجاز فایل‌های متنی نیست.',
		'Read-only filesystem tools are disabled in Mindio Magic MCP settings.'                                                              => 'ابزارهای فقط‌خواندنی فایل‌سیستم در تنظیمات Mindio Magic MCP غیرفعال هستند.',
		'The requested filesystem root is not allowlisted.'                                                                                      => 'ریشه درخواستی فایل‌سیستم در فهرست مجاز نیست.',
		'Filesystem paths must be safe relative paths without traversal.'                                                                       => 'مسیرهای فایل‌سیستم باید نسبی و امن باشند و پیمایش مسیر نداشته باشند.',
		'The requested path does not exist inside the selected root.'                                                                            => 'مسیر درخواستی داخل ریشه انتخاب‌شده وجود ندارد.',
		'The requested path is not the required file or directory type.'                                                                         => 'مسیر درخواستی از نوع فایل یا پوشه موردنیاز نیست.',
		'The selected file type or sensitive filename is not readable through MCP.'                                                             => 'نوع فایل انتخاب‌شده یا نام حساس آن از طریق MCP قابل خواندن نیست.',
		'List registered Gutenberg block types with category, title, and availability metadata.'                                               => 'فهرست انواع بلوک ثبت‌شده گوتنبرگ همراه با دسته، عنوان و فراداده دسترس‌پذیری.',
		'Get the registered attributes, supports, styles, and example markup for one or more Gutenberg block types.'                            => 'دریافت ویژگی‌های ثبت‌شده، قابلیت‌ها، سبک‌ها و نشانه‌گذاری نمونه برای یک یا چند نوع بلوک گوتنبرگ.',
		'Read a post as a structured Gutenberg block tree with stable-for-this-revision index paths.'                                           => 'خواندن نوشته به‌شکل درخت ساختاریافته بلوک گوتنبرگ با مسیرهای شاخص پایدار برای این بازبینی.',
		'List registered Gutenberg block patterns that can be inserted into posts.'                                                             => 'فهرست الگوهای ثبت‌شده بلوک گوتنبرگ که قابل درج در نوشته‌ها هستند.',
		'Insert one or more Gutenberg blocks at a root or nested block-tree position.'                                                          => 'درج یک یا چند بلوک گوتنبرگ در ریشه یا موقعیت تودرتوی درخت بلوک.',
		'Replace the Gutenberg block at an index path while preserving WordPress revision history.'                                             => 'جایگزینی بلوک گوتنبرگ در یک مسیر شاخص با حفظ تاریخچه بازبینی وردپرس.',
		'Remove a Gutenberg block and its descendants. Requires confirm=true.'                                                                  => 'حذف یک بلوک گوتنبرگ و زیرمجموعه‌های آن. به confirm=true نیاز دارد.',
		'Move a Gutenberg block to another root or nested position.'                                                                            => 'انتقال یک بلوک گوتنبرگ به ریشه یا موقعیت تودرتوی دیگر.',
		'Duplicate a Gutenberg block and insert the copy immediately after it.'                                                                 => 'تکثیر یک بلوک گوتنبرگ و درج نسخه کپی بلافاصله پس از آن.',
		'Insert a registered Gutenberg pattern at a root or nested block-tree position.'                                                        => 'درج یک الگوی ثبت‌شده گوتنبرگ در ریشه یا موقعیت تودرتوی درخت بلوک.',
		'Provide name or names for the block schemas to retrieve.'                                                                               => 'نام یا نام‌های شِمای بلوک‌های موردنظر برای دریافت را ارائه کنید.',
		'Removing a block requires confirm=true.'                                                                                                => 'حذف بلوک به confirm=true نیاز دارد.',
		'The requested Gutenberg pattern is not registered.'                                                                                    => 'الگوی درخواستی گوتنبرگ ثبت نشده است.',
		'The post changed after the block tree was read. Fetch the current blocks and retry.'                                                   => 'نوشته پس از خواندن درخت بلوک تغییر کرده است. بلوک‌های فعلی را دریافت و دوباره تلاش کنید.',
		'This post contains Flatsome UX Builder content. Use Flatsome tools, or pass force_non_gutenberg=true and confirm=true to override.'    => 'این نوشته محتوای Flatsome UX Builder دارد. از ابزارهای Flatsome استفاده کنید یا برای بازنویسی force_non_gutenberg=true و confirm=true را ارسال کنید.',
		'Block markup cannot be empty.'                                                                                                          => 'نشانه‌گذاری بلوک نمی‌تواند خالی باشد.',
		'Get post SEO'                                                                                                                          => 'دریافت سئوی نوشته',
		'Read title, description, focus keyword, robots, canonical, social, and schema data for one post.'                                     => 'خواندن عنوان، توضیحات، کلیدواژه کانونی، robots، نشانی canonical، داده‌های اجتماعی و شِما برای یک نوشته.',
		'Get term SEO'                                                                                                                          => 'دریافت سئوی عبارت',
		'Read title, description, focus keyword, robots, canonical, and social data for one taxonomy term.'                                   => 'خواندن عنوان، توضیحات، کلیدواژه کانونی، robots، نشانی canonical و داده‌های اجتماعی برای یک عبارت رده‌بندی.',
		'Get provider settings'                                                                                                                 => 'دریافت تنظیمات ارائه‌دهنده',
		'Read a curated set of safe homepage, social, robots, and title settings.'                                                              => 'خواندن مجموعه‌ای گزینش‌شده از تنظیمات امن صفحه نخست، شبکه‌های اجتماعی، robots و عنوان.',
		'Update post SEO'                                                                                                                       => 'به‌روزرسانی سئوی نوشته',
		'Update an allowlisted set of post SEO and social fields with optional concurrency protection.'                                       => 'به‌روزرسانی مجموعه مجاز فیلدهای سئو و اجتماعی نوشته با محافظت اختیاری هم‌زمانی.',
		'Update term SEO'                                                                                                                       => 'به‌روزرسانی سئوی عبارت',
		'Update an allowlisted set of taxonomy-term SEO and social fields.'                                                                    => 'به‌روزرسانی مجموعه مجاز فیلدهای سئو و اجتماعی عبارت رده‌بندی.',
		'Update provider settings'                                                                                                              => 'به‌روزرسانی تنظیمات ارائه‌دهنده',
		'Update only curated homepage, social, robots, and title settings. Requires confirmation.'                                            => 'فقط تنظیمات گزینش‌شده صفحه نخست، شبکه‌های اجتماعی، robots و عنوان را به‌روزرسانی می‌کند. به تأیید نیاز دارد.',
		'Taxonomy term not found.'                                                                                                              => 'عبارت رده‌بندی پیدا نشد.',
		'The post changed since it was read. Fetch it again before updating SEO data.'                                                         => 'نوشته پس از خوانده‌شدن تغییر کرده است. پیش از به‌روزرسانی داده‌های سئو دوباره آن را دریافت کنید.',
		'Yoast SEO Free generates its graph; use schema_page_type and schema_article_type instead of arbitrary schemas.'                       => 'Yoast SEO Free نمودار خود را تولید می‌کند؛ به‌جای شِماهای دلخواه از schema_page_type و schema_article_type استفاده کنید.',
		'Replacing Rank Math schemas requires replace_schemas=true and confirm=true.'                                                          => 'جایگزینی شِماهای Rank Math به replace_schemas=true و confirm=true نیاز دارد.',
		'Sitewide SEO setting updates require confirm=true.'                                                                                    => 'به‌روزرسانی تنظیمات سراسری سئو به confirm=true نیاز دارد.',
		'SEO setting "%s" is not in the curated allowlist.'                                                                                      => 'تنظیم سئوی «%s» در فهرست مجاز گزینش‌شده نیست.',
		'Robots directives contain unsupported or contradictory values.'                                                                       => 'دستورهای robots دارای مقادیر پشتیبانی‌نشده یا متناقض هستند.',
		'SEO setting "%s" has an invalid value or type.'                                                                                         => 'مقدار یا نوع تنظیم سئوی «%s» معتبر نیست.',
		'Every Rank Math schema must contain a safe @type value.'                                                                               => 'هر شِمای Rank Math باید یک مقدار امن @type داشته باشد.',
		'Rank Math schema types must be unique per update.'                                                                                     => 'نوع شِماهای Rank Math در هر به‌روزرسانی باید یکتا باشد.',
		'Get the active parent/child theme context and declared WordPress feature support.'                                                    => 'دریافت زمینه پوسته والد/فرزند فعال و پشتیبانی اعلام‌شده قابلیت‌های وردپرس.',
		'Read active-theme modifications with sensitive values redacted. Optionally select specific keys.'                                   => 'خواندن تغییرات پوسته فعال با پوشاندن مقادیر حساس. کلیدهای مشخص را می‌توان به‌صورت اختیاری انتخاب کرد.',
		'Update only the portable WordPress theme modifications in the documented allowlist. Requires confirm=true.'                          => 'فقط تغییرات قابل‌انتقال پوسته وردپرس در فهرست مجاز مستند را به‌روزرسانی می‌کند. به confirm=true نیاز دارد.',
		'Create a minimal child theme for the active parent theme and optionally activate it. Requires confirm=true.'                          => 'ایجاد پوسته فرزند حداقلی برای پوسته والد فعال و فعال‌سازی اختیاری آن. به confirm=true نیاز دارد.',
		'Read typed, curated Flatsome settings for colors, typography, layout, header, footer, blog, shop, and performance.'                  => 'خواندن تنظیمات نوع‌دار و گزینش‌شده Flatsome برای رنگ، تایپوگرافی، چیدمان، سربرگ، پابرگ، وبلاگ، فروشگاه و عملکرد.',
		'Update typed settings from the curated Flatsome allowlist. Unknown or structurally unsafe settings are rejected. Requires confirm=true.' => 'به‌روزرسانی تنظیمات نوع‌دار از فهرست مجاز گزینش‌شده Flatsome. تنظیمات ناشناخته یا از نظر ساختاری ناامن رد می‌شوند. به confirm=true نیاز دارد.',
		'Theme modification updates require confirm=true.'                                                                                     => 'به‌روزرسانی تغییرات پوسته به confirm=true نیاز دارد.',
		'Theme modification "%s" is not in the portable write allowlist.'                                                                       => 'تغییر پوسته «%s» در فهرست مجاز نوشتن قابل‌انتقال نیست.',
		'Child theme creation requires confirm=true.'                                                                                           => 'ایجاد پوسته فرزند به confirm=true نیاز دارد.',
		'Your user cannot activate the new child theme.'                                                                                        => 'کاربر شما اجازه فعال‌سازی پوسته فرزند جدید را ندارد.',
		'Choose a valid child-theme slug that differs from the parent theme.'                                                                   => 'نامک معتبر و متفاوت از پوسته والد برای پوسته فرزند انتخاب کنید.',
		'A theme already exists at the requested child-theme slug.'                                                                             => 'پوسته‌ای با نامک درخواستی پوسته فرزند از قبل وجود دارد.',
		'WordPress could not create the child-theme directory.'                                                                                 => 'وردپرس نتوانست پوشه پوسته فرزند را ایجاد کند.',
		'WordPress could not write the child-theme files.'                                                                                      => 'وردپرس نتوانست فایل‌های پوسته فرزند را بنویسد.',
		'Flatsome setting updates require confirm=true.'                                                                                        => 'به‌روزرسانی تنظیمات Flatsome به confirm=true نیاز دارد.',
		'Flatsome setting "%s" is not in the curated allowlist.'                                                                                 => 'تنظیم Flatsome «%s» در فهرست مجاز گزینش‌شده نیست.',
		'Primary color'                                                                                                                         => 'رنگ اصلی',
		'Secondary color'                                                                                                                       => 'رنگ ثانویه',
		'Success color'                                                                                                                         => 'رنگ موفقیت',
		'Alert color'                                                                                                                           => 'رنگ هشدار',
		'Base text color'                                                                                                                       => 'رنگ متن پایه',
		'Heading color'                                                                                                                         => 'رنگ عنوان‌ها',
		'Link color'                                                                                                                            => 'رنگ پیوند',
		'Link hover color'                                                                                                                      => 'رنگ پیوند هنگام اشاره',
		'Heading font'                                                                                                                          => 'فونت عنوان‌ها',
		'Base text font'                                                                                                                        => 'فونت متن پایه',
		'Navigation font'                                                                                                                       => 'فونت ناوبری',
		'Alternate font'                                                                                                                        => 'فونت جایگزین',
		'Base font size percent'                                                                                                                => 'درصد اندازه فونت پایه',
		'Mobile font size percent'                                                                                                              => 'درصد اندازه فونت موبایل',
		'Body layout'                                                                                                                           => 'چیدمان بدنه',
		'Content width'                                                                                                                         => 'عرض محتوا',
		'Boxed site width'                                                                                                                      => 'عرض سایت جعبه‌ای',
		'Content contrast'                                                                                                                      => 'کنتراست محتوا',
		'Content background'                                                                                                                    => 'پس‌زمینه محتوا',
		'Body background'                                                                                                                       => 'پس‌زمینه بدنه',
		'Header width'                                                                                                                          => 'عرض سربرگ',
		'Header height'                                                                                                                         => 'ارتفاع سربرگ',
		'Sticky header height'                                                                                                                  => 'ارتفاع سربرگ چسبان',
		'Header contrast'                                                                                                                       => 'کنتراست سربرگ',
		'Sticky header'                                                                                                                         => 'سربرگ چسبان',
		'Footer area one'                                                                                                                       => 'ناحیه نخست پابرگ',
		'Footer area two'                                                                                                                       => 'ناحیه دوم پابرگ',
		'Bottom footer alignment'                                                                                                               => 'تراز پایین پابرگ',
		'Bottom footer contrast'                                                                                                                => 'کنتراست پایین پابرگ',
		'Left footer text'                                                                                                                      => 'متن سمت چپ پابرگ',
		'Right footer text'                                                                                                                     => 'متن سمت راست پابرگ',
		'Blog archive sidebar'                                                                                                                  => 'نوار کناری بایگانی وبلاگ',
		'Single post sidebar'                                                                                                                   => 'نوار کناری نوشته تکی',
		'Blog pagination'                                                                                                                       => 'صفحه‌بندی وبلاگ',
		'Shop sidebar'                                                                                                                          => 'نوار کناری فروشگاه',
		'Product page layout'                                                                                                                   => 'چیدمان صفحه محصول',
		'Shop pagination'                                                                                                                       => 'صفحه‌بندی فروشگاه',
		'Live product search'                                                                                                                   => 'جست‌وجوی زنده محصول',
		'AJAX add to cart'                                                                                                                       => 'افزودن AJAX به سبد خرید',
		'Lazy-load images'                                                                                                                      => 'بارگذاری تنبل تصاویر',
		'Instant page preloading'                                                                                                               => 'پیش‌بارگذاری فوری صفحه',
		'Disable emoji scripts'                                                                                                                 => 'غیرفعال‌کردن اسکریپت‌های ایموجی',
		'Disable WordPress block CSS'                                                                                                           => 'غیرفعال‌کردن CSS بلوک‌های وردپرس',
		'Remove jQuery Migrate'                                                                                                                 => 'حذف jQuery Migrate',
		'Theme setting "%s" has an invalid value or type.'                                                                                       => 'مقدار یا نوع تنظیم پوسته «%s» معتبر نیست.',
		'The active theme is not Flatsome or a Flatsome child theme.'                                                                           => 'پوسته فعال Flatsome یا پوسته فرزند Flatsome نیست.',
		'The active stylesheet changed since the agent read the theme context.'                                                                => 'شیوه‌نامه فعال پس از خواندن زمینه پوسته توسط عامل تغییر کرده است.',
		'This site requires interactive filesystem credentials, so MCP cannot safely create a child theme.'                                  => 'این سایت به اطلاعات ورود تعاملی فایل‌سیستم نیاز دارد؛ بنابراین MCP نمی‌تواند پوسته فرزند را با ایمنی ایجاد کند.',
		'Unknown WooCommerce operation.'                                                                                                        => 'عملیات ووکامرس ناشناخته است.',
		'This WooCommerce operation requires confirm=true.'                                                                                     => 'این عملیات ووکامرس به confirm=true نیاز دارد.',
		'WooCommerce rejected the operation.'                                                                                                   => 'ووکامرس عملیات را رد کرد.',
		'System status'                                                                                                                         => 'وضعیت سیستم',
		'Read WooCommerce data using the fixed %s endpoint.'                                                                                    => 'خواندن داده‌های ووکامرس با استفاده از نقطه پایانی ثابت %s.',
		'Mutate WooCommerce data using the fixed %s endpoint.'                                                                                  => 'تغییر داده‌های ووکامرس با استفاده از نقطه پایانی ثابت %s.',
		'WooCommerce operation payloads may not exceed 512 KB.'                                                                                 => 'حجم داده ورودی عملیات ووکامرس نباید از ۵۱۲ کیلوبایت بیشتر باشد.',
		'WooCommerce operation payload is too deeply nested or complex.'                                                                       => 'داده ورودی عملیات ووکامرس بیش از حد تودرتو یا پیچیده است.',
		'Secret and password fields are not writable through the WooCommerce MCP dispatcher.'                                                 => 'فیلدهای رمز و گذرواژه از طریق توزیع‌کننده MCP ووکامرس قابل نوشتن نیستند.',
		'A WooCommerce payload value is unsafe or too long.'                                                                                    => 'یکی از مقادیر داده ورودی ووکامرس ناامن یا بیش از حد طولانی است.',
		'WooCommerce payload values must be valid JSON values.'                                                                                 => 'مقادیر داده ورودی ووکامرس باید مقادیر معتبر JSON باشند.',
		'WooCommerce payload contains an invalid or reserved key.'                                                                              => 'داده ورودی ووکامرس دارای کلید نامعتبر یا رزروشده است.',
	);
	if ( isset( $translations[ $msgid ] ) ) {
		return $translations[ $msgid ];
	}

	$woo_actions = array(
		'List'   => 'فهرست',
		'Get'    => 'دریافت',
		'Create' => 'ایجاد',
		'Update' => 'به‌روزرسانی',
		'Delete' => 'حذف',
		'Run'    => 'اجرای',
	);
	$woo_entities = array(
		'status tools'             => 'ابزارهای وضعیت',
		'products'                 => 'محصولات',
		'product'                  => 'محصول',
		'product variations'       => 'گونه‌های محصول',
		'product variation'        => 'گونه محصول',
		'product categories'       => 'دسته‌های محصول',
		'product category'         => 'دسته محصول',
		'product tags'             => 'برچسب‌های محصول',
		'product tag'              => 'برچسب محصول',
		'product attributes'       => 'ویژگی‌های محصول',
		'product attribute'        => 'ویژگی محصول',
		'attribute terms'          => 'مقادیر ویژگی',
		'attribute term'           => 'مقدار ویژگی',
		'product reviews'          => 'دیدگاه‌های محصول',
		'product review'           => 'دیدگاه محصول',
		'orders'                   => 'سفارش‌ها',
		'order'                    => 'سفارش',
		'order notes'              => 'یادداشت‌های سفارش',
		'order note'               => 'یادداشت سفارش',
		'order refunds'            => 'بازپرداخت‌های سفارش',
		'order refund'             => 'بازپرداخت سفارش',
		'coupons'                  => 'کدهای تخفیف',
		'coupon'                   => 'کد تخفیف',
		'customers'                => 'مشتریان',
		'customer'                 => 'مشتری',
		'customer downloads'       => 'دانلودهای مشتری',
		'tax rates'                => 'نرخ‌های مالیات',
		'tax rate'                 => 'نرخ مالیات',
		'tax classes'              => 'رده‌های مالیاتی',
		'tax class'                => 'رده مالیاتی',
		'shipping zones'           => 'مناطق ارسال',
		'shipping zone'            => 'منطقه ارسال',
		'shipping-zone locations'  => 'مکان‌های منطقه ارسال',
		'shipping-zone methods'    => 'روش‌های منطقه ارسال',
		'shipping-zone method'     => 'روش منطقه ارسال',
		'shipping methods'         => 'روش‌های ارسال',
		'payment gateways'         => 'درگاه‌های پرداخت',
		'payment gateway'          => 'درگاه پرداخت',
		'setting groups'           => 'گروه‌های تنظیمات',
		'setting group'            => 'گروه تنظیمات',
		'setting option'           => 'گزینه تنظیمات',
		'WooCommerce webhooks'     => 'وب‌هوک‌های ووکامرس',
		'WooCommerce webhook'      => 'وب‌هوک ووکامرس',
		'countries'                => 'کشورها',
		'currencies'               => 'ارزها',
		'continents'               => 'قاره‌ها',
		'sales report'             => 'گزارش فروش',
		'top sellers report'       => 'گزارش پرفروش‌ها',
		'order totals report'      => 'گزارش مجموع سفارش‌ها',
		'product totals report'    => 'گزارش مجموع محصولات',
		'customer totals report'   => 'گزارش مجموع مشتریان',
		'coupon totals report'     => 'گزارش مجموع کدهای تخفیف',
		'review totals report'     => 'گزارش مجموع دیدگاه‌ها',
		'system status tool'       => 'ابزار وضعیت سیستم',
	);
	if ( preg_match( '/^(List|Get|Create|Update|Delete|Run) (.+)$/', $msgid, $matches )
		&& isset( $woo_entities[ $matches[2] ] ) ) {
		return $woo_actions[ $matches[1] ] . ' ' . $woo_entities[ $matches[2] ];
	}
	return null;
}

$entries       = preg_split( '/\R{2,}/', trim( $source ) ) ?: array();
$untranslated  = array();
$translated    = 0;
foreach ( $entries as &$entry ) {
	$msgid = fmp_fa_po_field( $entry, 'msgid' );
	if ( null === $msgid || '' === $msgid ) {
		continue;
	}
	$plural = fmp_fa_po_field( $entry, 'msgid_plural' );
	$needs  = null === $plural
		? '' === fmp_fa_po_field( $entry, 'msgstr' )
		: '' === fmp_fa_po_field( $entry, 'msgstr[0]' ) || '' === fmp_fa_po_field( $entry, 'msgstr[1]' );
	if ( ! $needs ) {
		continue;
	}
	$translation = fmp_fa_translation( $msgid, $plural );
	if ( null === $translation ) {
		$untranslated[] = array( 'msgid' => $msgid, 'plural' => $plural );
		continue;
	}
	if ( null === $plural && is_string( $translation ) ) {
		$quoted = json_encode( $translation, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$entry  = preg_replace( '/^msgstr ""$/m', 'msgstr ' . $quoted, $entry, 1 ) ?? $entry;
		++$translated;
		continue;
	}
	if ( null !== $plural && is_array( $translation ) ) {
		foreach ( array( 0, 1 ) as $index ) {
			$quoted = json_encode( $translation[ $index ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			$entry  = preg_replace( '/^msgstr\[' . $index . '\] ""$/m', 'msgstr[' . $index . '] ' . $quoted, $entry, 1 ) ?? $entry;
		}
		++$translated;
	}
}
unset( $entry );

if ( in_array( '--write', $argv, true ) ) {
	file_put_contents( $path, implode( "\n\n", $entries ) . "\n" );
}

$offset = 0;
$limit  = count( $untranslated );
foreach ( $argv as $argument ) {
	if ( str_starts_with( $argument, '--offset=' ) ) {
		$offset = max( 0, (int) substr( $argument, 9 ) );
	}
	if ( str_starts_with( $argument, '--limit=' ) ) {
		$limit = max( 1, (int) substr( $argument, 8 ) );
	}
}
$display = array_slice( $untranslated, $offset, $limit );

echo json_encode(
	array( 'filled' => $translated, 'remaining' => count( $untranslated ), 'offset' => $offset, 'entries' => $display ),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
) . PHP_EOL;

exit( $untranslated ? 2 : 0 );
