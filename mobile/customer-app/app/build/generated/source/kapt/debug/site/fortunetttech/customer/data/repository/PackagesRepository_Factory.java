package site.fortunetttech.customer.data.repository;

import dagger.internal.DaggerGenerated;
import dagger.internal.Factory;
import dagger.internal.QualifierMetadata;
import dagger.internal.ScopeMetadata;
import javax.annotation.processing.Generated;
import javax.inject.Provider;
import site.fortunetttech.customer.data.network.ApiService;

@ScopeMetadata("javax.inject.Singleton")
@QualifierMetadata
@DaggerGenerated
@Generated(
    value = "dagger.internal.codegen.ComponentProcessor",
    comments = "https://dagger.dev"
)
@SuppressWarnings({
    "unchecked",
    "rawtypes",
    "KotlinInternal",
    "KotlinInternalInJava",
    "cast"
})
public final class PackagesRepository_Factory implements Factory<PackagesRepository> {
  private final Provider<ApiService> apiProvider;

  public PackagesRepository_Factory(Provider<ApiService> apiProvider) {
    this.apiProvider = apiProvider;
  }

  @Override
  public PackagesRepository get() {
    return newInstance(apiProvider.get());
  }

  public static PackagesRepository_Factory create(Provider<ApiService> apiProvider) {
    return new PackagesRepository_Factory(apiProvider);
  }

  public static PackagesRepository newInstance(ApiService api) {
    return new PackagesRepository(api);
  }
}
