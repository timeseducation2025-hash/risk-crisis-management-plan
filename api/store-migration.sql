CREATE TABLE IF NOT EXISTS store_orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  store_key ENUM('scout','bodycare') NOT NULL DEFAULT 'scout',
  order_code VARCHAR(24) NULL UNIQUE,
  user_email VARCHAR(190) DEFAULT NULL,
  customer_name VARCHAR(120) NOT NULL,
  phone VARCHAR(30) NOT NULL,
  gov VARCHAR(120) NOT NULL,
  address VARCHAR(300) NOT NULL,
  note VARCHAR(400) DEFAULT '',
  total DECIMAL(12,2) NOT NULL DEFAULT 0,
  status ENUM('new','confirmed','processing','shipped','fulfilled','cancelled') DEFAULT 'new',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX(status),
  INDEX(store_key),
  INDEX(created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE store_orders MODIFY status ENUM('new','confirmed','processing','shipped','fulfilled','cancelled') DEFAULT 'new';

CREATE TABLE IF NOT EXISTS store_order_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  product_id VARCHAR(48) NOT NULL,
  product_name VARCHAR(160) NOT NULL,
  unit_price DECIMAL(12,2) NOT NULL,
  quantity INT NOT NULL,
  line_total DECIMAL(12,2) NOT NULL,
  CONSTRAINT fk_store_order_items_order FOREIGN KEY (order_id) REFERENCES store_orders(id) ON DELETE CASCADE,
  INDEX(order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE store_order_items MODIFY product_id VARCHAR(48) NOT NULL;

CREATE TABLE IF NOT EXISTS store_products (
  id INT AUTO_INCREMENT PRIMARY KEY,
  store_key ENUM('scout','bodycare') NOT NULL,
  product_key VARCHAR(48) NOT NULL,
  name VARCHAR(160) NOT NULL,
  category VARCHAR(80) DEFAULT '',
  description VARCHAR(500) DEFAULT '',
  image_url VARCHAR(500) DEFAULT '',
  base_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  stock INT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_store_product (store_key,product_key),
  INDEX(store_key,active),
  INDEX(category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS store_staff_permissions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_email VARCHAR(190) NOT NULL,
  store_key ENUM('scout','bodycare','both') NOT NULL,
  can_inventory TINYINT(1) NOT NULL DEFAULT 0,
  can_orders TINYINT(1) NOT NULL DEFAULT 0,
  assigned_by VARCHAR(190) NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_store_staff (user_email,store_key),
  INDEX(user_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS store_order_events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  actor_email VARCHAR(190) NOT NULL,
  old_status VARCHAR(24) DEFAULT '',
  new_status VARCHAR(24) NOT NULL,
  note VARCHAR(300) DEFAULT '',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_store_order_events_order FOREIGN KEY (order_id) REFERENCES store_orders(id) ON DELETE CASCADE,
  INDEX(order_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS store_stock_events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  store_key ENUM('scout','bodycare') NOT NULL,
  product_key VARCHAR(48) NOT NULL,
  actor_email VARCHAR(190) NOT NULL,
  change_qty INT NOT NULL,
  stock_after INT NOT NULL,
  reason VARCHAR(40) NOT NULL,
  order_id INT DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX(store_key,product_key,created_at),
  INDEX(order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO store_products(store_key,product_key,name,category,description,image_url,base_price,stock,active) VALUES
('scout','pack','حقيبة ظهر للرحلات','تجهيز الرحلات','حقيبة متعددة الجيوب للرحلات والأنشطة الخارجية.','https://images.unsplash.com/photo-1553062407-98eeb64c6a62?auto=format&fit=crop&w=700&q=80',1250,0,1),
('scout','lamp','مصباح تخييم قابل للشحن','تجهيز المخيم','إضاءة عملية للمخيم والأنشطة المسائية.','https://images.unsplash.com/photo-1500530855697-b586d89ba3ee?auto=format&fit=crop&w=700&q=80',420,0,1),
('scout','bottle','زجاجة مياه ميدانية','مستلزمات يومية','زجاجة قابلة لإعادة الاستخدام للأنشطة والرحلات.','https://images.unsplash.com/photo-1602143407151-7111542de6e8?auto=format&fit=crop&w=700&q=80',190,0,1),
('scout','compass','بوصلة تدريبية','مهارات ميدانية','أداة تدريب للملاحة وقراءة الاتجاهات.','https://images.unsplash.com/photo-1500534623283-312aade485b7?auto=format&fit=crop&w=700&q=80',280,0,1),
('scout','aidkit','حقيبة إسعافات أولية أساسية','السلامة والعناية','حافظة منظمة لمستلزمات الإسعاف الأولي الأساسية.','https://images.unsplash.com/photo-1603398938378-e54eab446dde?auto=format&fit=crop&w=700&q=80',360,0,1),
('scout','thermo','ترمومتر رقمي','العناية الأساسية','جهاز قياس منزلي؛ اتبعي تعليمات الشركة المصنّعة.','https://images.unsplash.com/photo-1584362917165-526a968579e8?auto=format&fit=crop&w=700&q=80',240,0,1),
('scout','sanitizer','عبوة تعقيم قابلة لإعادة التعبئة','النظافة الشخصية','عبوة ميدانية صغيرة للاستخدام أثناء النشاط.','https://images.unsplash.com/photo-1584744982491-665216d95f8b?auto=format&fit=crop&w=700&q=80',75,0,1),
('scout','blanket','غطاء طوارئ حراري','السلامة والعناية','غطاء خفيف ضمن تجهيز حقيبة الطوارئ.','https://images.unsplash.com/photo-1511497584788-876760111969?auto=format&fit=crop&w=700&q=80',95,0,1),
('bodycare','cleanser','منظف لطيف للبشرة','العناية بالبشرة','تنظيف يومي لطيف ضمن روتين العناية.','https://images.unsplash.com/photo-1556228578-0d85b1a4d571?auto=format&fit=crop&w=700&q=80',130,0,1),
('bodycare','body_oil','زيت عناية للجسم','العناية بالجسم','زيت تجميلي للاستخدام الخارجي بعد الاستحمام.','https://images.unsplash.com/photo-1601049541289-9b1b7bbbfe19?auto=format&fit=crop&w=700&q=80',180,0,1),
('bodycare','lotion','لوشن مرطب للجسم','العناية بالجسم','ترطيب يومي بملمس خفيف.','https://images.unsplash.com/photo-1608248543803-ba4f8c70ae0b?auto=format&fit=crop&w=700&q=80',160,0,1),
('bodycare','soap','صابون نباتي','أساسيات يومية','قطعة عناية يومية للجسم.','https://images.unsplash.com/photo-1600857544200-b2f666a9a2ec?auto=format&fit=crop&w=700&q=80',70,0,1),
('bodycare','sunscreen','واقي شمس يومي','العناية بالبشرة','منتج تجميلي؛ اتبعي إرشادات العبوة.','https://images.unsplash.com/photo-1556229010-6c3f2c9ca5f8?auto=format&fit=crop&w=700&q=80',200,0,1),
('bodycare','lip_balm','مرطب شفاه','أساسيات يومية','أساس صغير للعناية اليومية.','https://images.unsplash.com/photo-1586495777744-4413f21062fa?auto=format&fit=crop&w=700&q=80',60,0,1),
('bodycare','scrub','مقشر للجسم','العناية بالجسم','تقشير تجميلي للجسم حسب إرشادات المنتج.','https://images.unsplash.com/photo-1570194065650-d99fb4bedf0a?auto=format&fit=crop&w=700&q=80',140,0,1),
('bodycare','hand_cream','كريم عناية لليدين','أساسيات يومية','عناية يومية لليدين.','https://images.unsplash.com/photo-1601612628452-9e99ced43524?auto=format&fit=crop&w=700&q=80',90,0,1);
