// SPDX-License-Identifier: MIT
pragma solidity ^0.8.19;

contract DangKySanPham {
    address public chu_so_huu;
    
    // Cấu trúc dữ liệu
    struct NguoiDung {
        address vi_tien;
        string ten_nguoi_dung;
        string email;
        string ten_cong_ty;
        string vai_tro;
        string so_dien_thoai;
        string dia_chi;
        bool da_kich_hoat;
        uint256 ngay_tao;
        uint256 lan_dang_nhap_cuoi;
    }
    
    struct SanPham {
        uint256 id;
        string ma_san_pham;
        string ten_san_pham;
        string mo_ta;
        uint256 danh_muc_id;
        uint256 nha_san_xuat_id;
        string don_vi_tinh;
        uint256 so_luong;
        string hinh_anh;
        string thong_so_ky_thuat;
        string ngay_thu_hoach;
        string ghi_chu;
        uint256 ngay_tao;
        uint256 ngay_cap_nhat;
        string tinh_thanh;
        string quan_huyen;
        string xa_phuong;
        string dia_chi_cu_the;
        bool da_duoc_duyet;
    }
    
    struct DonHangThuMua {
        uint256 id;
        string ma_don_thu_mua;
        uint256 nha_san_xuat_id;
        uint256 moi_gioi_id;
        uint256 kho_nhap_id;
        uint256 tong_tien;
        uint8 trang_thai; // 0: Chờ xử lý, 1: Đã duyệt, 2: Đã giao, 3: Đã hủy
        uint256 ngay_dat_hang;
        uint256 ngay_du_kien_nhan;
        uint256 ngay_cap_nhat;
        string ghi_chu;
    }
    
    // Ánh xạ dữ liệu
    mapping(address => NguoiDung) public nguoi_dung;
    mapping(uint256 => SanPham) public san_pham;
    mapping(uint256 => DonHangThuMua) public don_hang_thu_mua;
    mapping(address => bool) public nguoi_dung_da_dang_ky;
    mapping(string => bool) public ma_san_pham_da_ton_tai;
    mapping(string => bool) public ma_don_hang_da_ton_tai;
    
    // Biến đếm
    uint256 public so_luong_nguoi_dung;
    uint256 public so_luong_san_pham;
    uint256 public so_luong_don_hang;
    
    // Sự kiện
    event NguoiDungDaDangKy(
        address indexed vi_tien,
        string ten_nguoi_dung,
        string ten_cong_ty,
        string vai_tro,
        uint256 ngay_tao
    );
    
    event SanPhamDaDangKy(
        uint256 indexed id_san_pham,
        string ma_san_pham,
        string ten_san_pham,
        uint256 nha_san_xuat_id,
        uint256 so_luong,
        uint256 ngay_tao
    );
    
    event DonHangDaTao(
        uint256 indexed id_don_hang,
        string ma_don_thu_mua,
        uint256 nha_san_xuat_id,
        uint256 moi_gioi_id,
        uint256 tong_tien,
        uint8 trang_thai,
        uint256 ngay_dat_hang
    );
    
    event TrangThaiDonHangDaCapNhat(
        uint256 indexed id_don_hang,
        uint8 trang_thai_moi,
        uint256 ngay_cap_nhat
    );
    
    // Điều kiện
    modifier chi_chu_so_huu() {
        require(msg.sender == chu_so_huu, "Chi chu so huu duoc goi ham nay");
        _;
    }
    
    modifier chi_nguoi_dung_da_dang_ky() {
        require(nguoi_dung_da_dang_ky[msg.sender], "Nguoi dung chua dang ky");
        _;
    }
    
    constructor() {
        chu_so_huu = msg.sender;
        so_luong_nguoi_dung = 0;
        so_luong_san_pham = 0;
        so_luong_don_hang = 0;
    }
    
    // Đăng ký người dùng mới
    function dangKyNguoiDung(
        string memory _ten_nguoi_dung,
        string memory _email,
        string memory _ten_cong_ty,
        string memory _vai_tro,
        string memory _so_dien_thoai,
        string memory _dia_chi
    ) public {
        require(!nguoi_dung_da_dang_ky[msg.sender], "Nguoi dung da dang ky");
        require(bytes(_ten_nguoi_dung).length > 0, "Ten nguoi dung khong duoc de trong");
        
        so_luong_nguoi_dung++;
        
        nguoi_dung[msg.sender] = NguoiDung({
            vi_tien: msg.sender,
            ten_nguoi_dung: _ten_nguoi_dung,
            email: _email,
            ten_cong_ty: _ten_cong_ty,
            vai_tro: _vai_tro,
            so_dien_thoai: _so_dien_thoai,
            dia_chi: _dia_chi,
            da_kich_hoat: true,
            ngay_tao: block.timestamp,
            lan_dang_nhap_cuoi: block.timestamp
        });
        
        nguoi_dung_da_dang_ky[msg.sender] = true;
        
        emit NguoiDungDaDangKy(
            msg.sender,
            _ten_nguoi_dung,
            _ten_cong_ty,
            _vai_tro,
            block.timestamp
        );
    }
    
    // Cập nhật thông tin người dùng
    function capNhatNguoiDung(
        string memory _ten_nguoi_dung,
        string memory _email,
        string memory _ten_cong_ty,
        string memory _so_dien_thoai,
        string memory _dia_chi
    ) public chi_nguoi_dung_da_dang_ky {
        NguoiDung storage user = nguoi_dung[msg.sender];
        
        user.ten_nguoi_dung = _ten_nguoi_dung;
        user.email = _email;
        user.ten_cong_ty = _ten_cong_ty;
        user.so_dien_thoai = _so_dien_thoai;
        user.dia_chi = _dia_chi;
        user.lan_dang_nhap_cuoi = block.timestamp;
    }
    
    // Đăng ký sản phẩm mới
    function dangKySanPham(
        string memory _ma_san_pham,
        string memory _ten_san_pham,
        string memory _mo_ta,
        uint256 _danh_muc_id,
        string memory _don_vi_tinh,
        uint256 _so_luong,
        string memory _hinh_anh,
        string memory _thong_so_ky_thuat,
        string memory _tinh_thanh,
        string memory _quan_huyen,
        string memory _xa_phuong,
        string memory _dia_chi_cu_the,
        string memory _ngay_thu_hoach,
        string memory _ghi_chu
    ) public chi_nguoi_dung_da_dang_ky {
        require(!ma_san_pham_da_ton_tai[_ma_san_pham], "Ma san pham da ton tai");
        require(bytes(_ten_san_pham).length > 0, "Ten san pham khong duoc de trong");
        require(_so_luong > 0, "So luong phai lon hon 0");
        
        so_luong_san_pham++;
        
        san_pham[so_luong_san_pham] = SanPham({
            id: so_luong_san_pham,
            ma_san_pham: _ma_san_pham,
            ten_san_pham: _ten_san_pham,
            mo_ta: _mo_ta,
            danh_muc_id: _danh_muc_id,
            nha_san_xuat_id: layChiSoNguoiDung(msg.sender),
            don_vi_tinh: _don_vi_tinh,
            so_luong: _so_luong,
            hinh_anh: _hinh_anh,
            thong_so_ky_thuat: _thong_so_ky_thuat,
            ngay_thu_hoach: _ngay_thu_hoach,
            ghi_chu: _ghi_chu,
            ngay_tao: block.timestamp,
            ngay_cap_nhat: block.timestamp,
            tinh_thanh: _tinh_thanh,
            quan_huyen: _quan_huyen,
            xa_phuong: _xa_phuong,
            dia_chi_cu_the: _dia_chi_cu_the,
            da_duoc_duyet: true
        });
        
        ma_san_pham_da_ton_tai[_ma_san_pham] = true;
        
        emit SanPhamDaDangKy(
            so_luong_san_pham,
            _ma_san_pham,
            _ten_san_pham,
            layChiSoNguoiDung(msg.sender),
            _so_luong,
            block.timestamp
        );
    }
    
    // Tạo đơn hàng thu mua
    function taoDonHangThuMua(
        string memory _ma_don_thu_mua,
        uint256 _nha_san_xuat_id,
        uint256 _moi_gioi_id,
        uint256 _kho_nhap_id,
        uint256 _tong_tien,
        uint256 _ngay_du_kien_nhan,
        string memory _ghi_chu
    ) public chi_nguoi_dung_da_dang_ky {
        require(!ma_don_hang_da_ton_tai[_ma_don_thu_mua], "Ma don hang da ton tai");
        require(_tong_tien > 0, "Tong tien phai lon hon 0");
        
        so_luong_don_hang++;
        
        don_hang_thu_mua[so_luong_don_hang] = DonHangThuMua({
            id: so_luong_don_hang,
            ma_don_thu_mua: _ma_don_thu_mua,
            nha_san_xuat_id: _nha_san_xuat_id,
            moi_gioi_id: _moi_gioi_id,
            kho_nhap_id: _kho_nhap_id,
            tong_tien: _tong_tien,
            trang_thai: 0, // Chờ xử lý
            ngay_dat_hang: block.timestamp,
            ngay_du_kien_nhan: _ngay_du_kien_nhan,
            ngay_cap_nhat: block.timestamp,
            ghi_chu: _ghi_chu
        });
        
        ma_don_hang_da_ton_tai[_ma_don_thu_mua] = true;
        
        emit DonHangDaTao(
            so_luong_don_hang,
            _ma_don_thu_mua,
            _nha_san_xuat_id,
            _moi_gioi_id,
            _tong_tien,
            0,
            block.timestamp
        );
    }
    
    // Cập nhật trạng thái đơn hàng
    function capNhatTrangThaiDonHang(uint256 _id_don_hang, uint8 _trang_thai_moi) public chi_nguoi_dung_da_dang_ky {
        require(_id_don_hang > 0 && _id_don_hang <= so_luong_don_hang, "ID don hang khong hop le");
        require(_trang_thai_moi <= 3, "Trang thai khong hop le");
        
        DonHangThuMua storage don_hang = don_hang_thu_mua[_id_don_hang];
        don_hang.trang_thai = _trang_thai_moi;
        don_hang.ngay_cap_nhat = block.timestamp;
        
        emit TrangThaiDonHangDaCapNhat(_id_don_hang, _trang_thai_moi, block.timestamp);
    }
    
    // Hàm hỗ trợ
    function layChiSoNguoiDung(address _vi_tien) public view returns (uint256) {
        // Hàm này trả về chỉ số người dùng
        // Trong thực tế cần có cơ chế ánh xạ phù hợp
        return 1; // Tạm thời trả về 1
    }
    
    function layThongTinNguoiDung(address _vi_tien) public view returns (NguoiDung memory) {
        require(nguoi_dung_da_dang_ky[_vi_tien], "Khong tim thay nguoi dung");
        return nguoi_dung[_vi_tien];
    }
    
    function layThongTinSanPham(uint256 _id_san_pham) public view returns (SanPham memory) {
        require(_id_san_pham > 0 && _id_san_pham <= so_luong_san_pham, "ID san pham khong hop le");
        return san_pham[_id_san_pham];
    }
    
    function layThongTinDonHang(uint256 _id_don_hang) public view returns (DonHangThuMua memory) {
        require(_id_don_hang > 0 && _id_don_hang <= so_luong_don_hang, "ID don hang khong hop le");
        return don_hang_thu_mua[_id_don_hang];
    }
    
    function laySanPhamTheoNhaSanXuat(uint256 _nha_san_xuat_id) public view returns (SanPham[] memory) {
        uint256 dem = 0;
        for (uint256 i = 1; i <= so_luong_san_pham; i++) {
            if (san_pham[i].nha_san_xuat_id == _nha_san_xuat_id && san_pham[i].da_duoc_duyet) {
                dem++;
            }
        }
        
        SanPham[] memory san_pham_nha_san_xuat = new SanPham[](dem);
        uint256 chi_so = 0;
        for (uint256 i = 1; i <= so_luong_san_pham; i++) {
            if (san_pham[i].nha_san_xuat_id == _nha_san_xuat_id && san_pham[i].da_duoc_duyet) {
                san_pham_nha_san_xuat[chi_so] = san_pham[i];
                chi_so++;
            }
        }
        
        return san_pham_nha_san_xuat;
    }
}